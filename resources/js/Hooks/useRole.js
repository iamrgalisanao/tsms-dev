import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();
    let role = null;
    if (user) {
        if (user.role) {
            role = user.role.toUpperCase();
        } else if (user.roles && Array.isArray(user.roles) && user.roles.length > 0) {
            role = typeof user.roles[0] === 'string' ? user.roles[0].toUpperCase() : null;
        }
    }
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
