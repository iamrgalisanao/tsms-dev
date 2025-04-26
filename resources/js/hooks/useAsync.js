import React from 'react';

export function useAsync(asyncFunction, immediate = true) {
  const [status, setStatus] = React.useState('idle');
  const [data, setData] = React.useState(null);
  const [error, setError] = React.useState(null);

  // Store the function in a ref to avoid unnecessary rerenders
  const asyncFunctionRef = React.useRef(asyncFunction);
  React.useEffect(() => {
    asyncFunctionRef.current = asyncFunction;
  }, [asyncFunction]);

  const execute = React.useCallback((...params) => {
    setStatus('pending');
    setData(null);
    setError(null);

    return asyncFunctionRef
      .current(...params)
      .then((response) => {
        setData(response);
        setStatus('success');
        return response;
      })
      .catch((error) => {
        setError(error);
        setStatus('error');
        throw error;
      });
  }, []);

  React.useEffect(() => {
    if (immediate) {
      execute();
    }
  }, [execute, immediate]);

  return {
    execute,
    status,
    data,
    error,
    isLoading: status === 'pending',
    isSuccess: status === 'success',
    isError: status === 'error'
  };
}

export function usePagination(initialPage = 1, initialPerPage = 15) {
  const [pagination, setPagination] = React.useState({
    page: initialPage,
    perPage: initialPerPage,
    total: 0,
    lastPage: 1
  });

  const updatePagination = React.useCallback((newData) => {
    if (newData?.meta) {
      setPagination(prev => ({
        ...prev,
        page: newData.meta.current_page,
        lastPage: newData.meta.last_page,
        total: newData.meta.total
      }));
    }
  }, []);

  const setPage = React.useCallback((newPage) => {
    setPagination(prev => ({
      ...prev,
      page: newPage
    }));
  }, []);

  return {
    pagination,
    updatePagination,
    setPage
  };
}
