import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();

    const roles = [
        user?.role,
        ...(Array.isArray(user?.roles) ? user.roles : []),
    ]
        .map((role) => (typeof role === 'string' ? role : role?.name || ''))
        .map((role) => role.toLowerCase());

    // Tenant Management includes tenant edit/update actions, so the page-level
    // guard mirrors the route/API boundary and allows administrators only.
    return Boolean(user && roles.includes('admin'));
};
