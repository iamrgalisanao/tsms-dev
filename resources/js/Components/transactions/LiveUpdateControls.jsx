import React from 'react';
import {
    Box,
    Stack,
    Typography,
    IconButton,
    Chip,
    Tooltip
} from '@mui/material';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import PauseIcon from '@mui/icons-material/Pause';
import FiberManualRecordIcon from '@mui/icons-material/FiberManualRecord';
import { keyframes } from '@mui/system';

const pulse = keyframes`
  0% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
  100% {
    opacity: 1;
  }
`;

const LiveUpdateControls = ({ isPaused, onTogglePause, lastUpdate, newRecordsCount, onResetCount }) => {
    const formatLastUpdate = (date) => {
        if (!date) return 'Never';
        const seconds = Math.floor((new Date() - date) / 1000);
        if (seconds < 60) return `${seconds}s ago`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;
        const hours = Math.floor(minutes / 60);
        return `${hours}h ago`;
    };

    return (
        <Box sx={{
            display: 'flex',
            alignItems: 'center',
            gap: 2,
            p: 2,
            bgcolor: 'grey.50',
            borderRadius: 2,
            border: 1,
            borderColor: 'divider'
        }}>
            <Stack direction="row" alignItems="center" spacing={1}>
                <Tooltip title={isPaused ? 'Resume live updates' : 'Pause live updates'}>
                    <IconButton
                        size="small"
                        onClick={onTogglePause}
                        color={isPaused ? 'default' : 'primary'}
                    >
                        {isPaused ? <PlayArrowIcon /> : <PauseIcon />}
                    </IconButton>
                </Tooltip>

                {!isPaused && (
                    <FiberManualRecordIcon
                        sx={{
                            fontSize: 12,
                            color: 'success.main',
                            animation: `${pulse} 2s ease-in-out infinite`
                        }}
                    />
                )}

                <Typography variant="caption" sx={{ fontWeight: 'bold', color: 'grey.700' }}>
                    {isPaused ? 'Updates Paused' : 'Live Updates Active'}
                </Typography>
            </Stack>

            <Typography variant="caption" sx={{ color: 'grey.500' }}>
                Last updated: {formatLastUpdate(lastUpdate)}
            </Typography>

            {newRecordsCount > 0 && (
                <Chip
                    label={`${newRecordsCount} new record${newRecordsCount > 1 ? 's' : ''}`}
                    color="primary"
                    size="small"
                    onDelete={onResetCount}
                    sx={{ fontWeight: 'bold' }}
                />
            )}
        </Box>
    );
};

export default LiveUpdateControls;
