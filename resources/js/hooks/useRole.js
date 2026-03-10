import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();
    console.log('[useRole] Debug Info:', {
        user,
        role: user ? user.role : null,
        email: user ? user.email : null,
        name: user ? user.name : null,
        rolesArray: user && user.roles ? user.roles : null,
        isAdmin: user ? user.role === 'admin' : false,
        isCommercial: user ? user.role === 'commercial' : false,
    });
    return user && (user.role === 'admin' || user.role === 'commercial');
};
