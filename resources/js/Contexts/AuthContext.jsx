import React, { createContext, useState, useContext, useEffect } from 'react';

const AuthContext = createContext(null);

const readCookie = (name) => {
    const match = document.cookie.match(new RegExp(`(^|;)\\s*${name}=([^;]+)`));
    return match ? match.pop() : null;
};

const getCsrfHeaders = () => {
    const cookieToken = readCookie('XSRF-TOKEN');
    if (cookieToken) {
        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(cookieToken) };
        } catch (error) {
            return { 'X-XSRF-TOKEN': cookieToken };
        }
    }

    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
};

const refreshCsrfCookie = async () => {
    await fetch('/sanctum/csrf-cookie', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
    });
};

const postLogout = () => {
    return fetch('/logout', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders()
        },
        credentials: 'include'
    });
};

const postLogin = (email, password) => {
    return fetch('/login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders()
        },
        credentials: 'include',
        body: JSON.stringify({ email, password }),
    });
};

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loggingOut, setLoggingOut] = useState(false);

    useEffect(() => {
        checkAuth();
    }, []);

    const checkAuth = async () => {
        // First, trust window.authUser if it was set by Blade (standard session login)
        if (window.authUser) {
            setUser(window.authUser);
            setLoading(false);
            return;
        }

        // Otherwise, check for a token-based session (API login path)
        try {
            const token = localStorage.getItem('auth_token');
            if (token) {
                const response = await fetch('/api/auth/user', {
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Accept': 'application/json'
                    }
                });
                if (response.ok) {
                    const text = await response.text();
                    try {
                        const data = JSON.parse(text);
                        window.authUser = data;
                        setUser(data);
                    } catch (jsonError) {
                        // Response is not valid JSON (likely HTML error page)
                        console.error('Auth check failed: Invalid JSON', text);
                        setUser(null);
                    }
                } else {
                    localStorage.removeItem('auth_token');
                    setUser(null);
                }
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            setUser(null);
        } finally {
            setLoading(false);
        }
    };

    const login = async (email, password) => {
        try {
            let response = await postLogin(email, password);

            if (response.status === 419) {
                await refreshCsrfCookie();
                response = await postLogin(email, password);
            }

            if (!response.ok) {
                const err = await response.json();
                throw new Error(err.message || 'Login failed');
            }

            const data = await response.json();
            localStorage.removeItem('auth_token');

            // Sync window.authUser so role-based sidebar works immediately
            window.authUser = data.user;
            setUser(data.user);

            // Use server-provided role-aware redirect
            return data.redirect_url || '/';
        } catch (error) {
            console.error('Login failed:', error);
            throw error;
        }
    };

    const logout = async () => {
        if (loggingOut) return;
        setLoggingOut(true);
        let shouldRedirectToLogin = false;

        try {
            let response = await postLogout();

            if (response.status === 419) {
                await refreshCsrfCookie();
                response = await postLogout();
            }

            if (response.ok || response.status === 401) {
                shouldRedirectToLogin = true;
            } else {
                throw new Error(`Logout failed with status ${response.status}`);
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            if (shouldRedirectToLogin) {
                localStorage.removeItem('auth_token');
                sessionStorage.clear();
                window.authUser = null;
                setUser(null);
                window.location.replace('/login');
                return;
            }

            setLoggingOut(false);
        }
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, logout, loggingOut }}>
            {children}
        </AuthContext.Provider>
    );
};

export const useAuth = () => {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
};

export default AuthContext;
