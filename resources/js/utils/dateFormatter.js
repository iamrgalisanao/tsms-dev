/**
 * Utility to format transaction dates consistently across the application.
 * Normalizes timestamps by removing the 'Z' suffix to ensure they are 
 * treated as local time rather than UTC, avoiding incorrect timezone offsets.
 * 
 * @param {string} dateString - The raw timestamp from the API
 * @param {boolean} includeSeconds - Whether to include seconds in the output
 * @returns {string} - Formatted date string
 */
export const formatDate = (dateString, includeSeconds = true) => {
    if (!dateString) return 'N/A';
    
    // Normalize: Treat as local time by removing Z suffix if present
    const normalizedString = typeof dateString === 'string' 
        ? dateString.replace('Z', '') 
        : dateString;
        
    const date = new Date(normalizedString);
    
    // Fallback for invalid dates
    if (isNaN(date.getTime())) return '-';

    const options = {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    };

    if (includeSeconds) {
        options.second = '2-digit';
    }

    return date.toLocaleString('en-PH', options);
};

export const formatTimeOnly = (dateString) => {
    if (!dateString) return 'N/A';
    
    const normalizedString = typeof dateString === 'string' 
        ? dateString.replace('Z', '') 
        : dateString;
        
    const date = new Date(normalizedString);
    if (isNaN(date.getTime())) return '-';

    return date.toLocaleString('en-PH', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
};
