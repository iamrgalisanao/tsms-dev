import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();
    const role = user && user.role ? user.role.toUpperCase() : null;
    console.log('[useRole] Debug Info:', {
        user,
        role,
        email: user ? user.email : null,
        name: user ? user.name : null,
        rolesArray: user && user.roles ? user.roles : null,
        isAdmin: role === 'ADMIN',
        isCommercial: role === 'COMMERCIAL',
    });
    return user && (role === 'ADMIN' || role === 'COMMERCIAL');
};
