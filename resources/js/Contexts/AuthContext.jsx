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
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                if (response.ok) {
                    const data = await response.json();
                    // /api/auth/user returns the user object directly (not nested)
                    window.authUser = data;
                    setUser(data);
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
            const response = await fetch('/api/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }),
            });

            if (!response.ok) {
                const err = await response.json();
                throw new Error(err.message || 'Login failed');
            }

            const data = await response.json();
            localStorage.setItem('auth_token', data.token);

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
            const token = localStorage.getItem('auth_token');
            if (token) {
                await fetch('/api/auth/logout', {
                    method: 'POST',
                    headers: { 'Authorization': `Bearer ${token}` },
                    credentials: 'include'
                });
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            localStorage.removeItem('auth_token');
            window.authUser = null;
            setUser(null);
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
