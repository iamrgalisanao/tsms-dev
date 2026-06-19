import { QueryClient } from '@tanstack/react-query';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 60 * 1000,       // 1 minute before data is considered stale
            gcTime: 5 * 60 * 1000,      // 5 minutes cache retention
            retry: 2,
            refetchOnWindowFocus: false,
        },
    },
});

export default queryClient;
