import { useAuth } from '../Contexts/AuthContext';

export const useRole = () => {
    const { user } = useAuth();
    console.log('useRole debug:', user ? user.role : 'no user');
    return user && (user.role === 'admin' || user.role === 'commercial');
};
