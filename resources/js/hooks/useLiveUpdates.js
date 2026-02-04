import { useState, useEffect, useRef, useCallback } from 'react';

/**
 * Custom hook for live transaction updates
 * Polls the API every 30 seconds for new/changed records
 * @param {Object} filters - Current filter state
 * @param {boolean} enabled - Whether polling is enabled
 * @param {number} interval - Polling interval in milliseconds (default: 30000)
 * @returns {Object} - { newRecordsCount, changedRecords, lastUpdate, isPaused, togglePause, resetCounts }
 */
export const useLiveUpdates = (fetchFunction, filters, enabled = true, interval = 30000) => {
    const [newRecordsCount, setNewRecordsCount] = useState(0);
    const [changedRecords, setChangedRecords] = useState([]);
    const [lastUpdate, setLastUpdate] = useState(null);
    const [isPaused, setIsPaused] = useState(false);
    const intervalRef = useRef(null);
    const lastDataRef = useRef(null);

    const poll = useCallback(async () => {
        if (isPaused || !enabled) return;

        try {
            const response = await fetchFunction(filters, 1, 100); // Get first page for updates
            const currentData = response.data || [];

            if (lastDataRef.current) {
                // Detect new records (not in previous data)
                const newRecords = currentData.filter(
                    current => !lastDataRef.current.some(prev => prev.id === current.id)
                );

                // Detect changed records (status changes)
                const changed = currentData.filter(current => {
                    const prev = lastDataRef.current.find(p => p.id === current.id);
                    return prev && prev.validation_status !== current.validation_status;
                });

                if (newRecords.length > 0) {
                    setNewRecordsCount(prev => prev + newRecords.length);
                }

                if (changed.length > 0) {
                    setChangedRecords(changed.map(r => r.id));
                    // Clear changed records after 5 seconds
                    setTimeout(() => setChangedRecords([]), 5000);
                }
            }

            lastDataRef.current = currentData;
            setLastUpdate(new Date());
        } catch (error) {
            console.error('Live update poll error:', error);
        }
    }, [fetchFunction, filters, isPaused, enabled]);

    useEffect(() => {
        if (!enabled) return;

        // Initial poll
        poll();

        // Set up interval
        intervalRef.current = setInterval(poll, interval);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [poll, interval, enabled]);

    const togglePause = useCallback(() => {
        setIsPaused(prev => !prev);
    }, []);

    const resetCounts = useCallback(() => {
        setNewRecordsCount(0);
        setChangedRecords([]);
    }, []);

    return {
        newRecordsCount,
        changedRecords,
        lastUpdate,
        isPaused,
        togglePause,
        resetCounts
    };
};
