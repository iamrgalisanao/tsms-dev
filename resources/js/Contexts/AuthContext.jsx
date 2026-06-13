import React, { createContext, useState, useContext, useEffect } from 'react';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

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
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {})
                },
                credentials: 'include',
                body: JSON.stringify({ email, password }),
            });

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
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            await fetch('/logout', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {})
                },
                credentials: 'include'
            });
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            localStorage.removeItem('auth_token');
            window.authUser = null;
            setUser(null);
            window.location.href = '/login';
        }
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, logout }}>
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
