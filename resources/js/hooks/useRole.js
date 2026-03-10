import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();
    return user && (user.role === 'admin' || user.role === 'commercial');
};
