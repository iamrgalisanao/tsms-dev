import React from 'react';
import { Paper, Box, Typography, Stack, Chip, Divider, IconButton, Avatar, Collapse } from '@mui/material';
import TerminalIcon from '@mui/icons-material/Terminal';
import ToggleOnIcon from '@mui/icons-material/ToggleOn';
import ToggleOffIcon from '@mui/icons-material/ToggleOff';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';

const RecentEventsTimeline = ({
    filteredLogs = [],
    feedFilter = 'all',
    setFeedFilter,
    filterCounts = {},
    liveFeed = true,
    setLiveFeed,
    selectedLogId = null,
    setSelectedLogId
}) => {
    return (
        <Paper className="glass-container" sx={{ p: 4, height: 650, display: 'flex', flexDirection: 'column', borderRadius: '20px', border: '1px solid', borderColor: 'divider' }}>
            {/* Feed Header */}
            <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems="center" spacing={2} sx={{ mb: 3 }}>
                <Typography variant="h6" sx={{ fontWeight: 1000, display: 'flex', alignItems: 'center', color: '#101221', letterSpacing: '0.05em' }}>
                    <TerminalIcon sx={{ mr: 2, color: '#00f2ff' }} />
                    DIAGNOSTIC FORENSIC FEED
                </Typography>
                
                <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
                    {/* Filters list */}
                    <Stack direction="row" spacing={1} sx={{ overflowX: 'auto', py: 0.5 }}>
                        {['all', 'processed', 'failed', 'retries', 'duplicates'].map((filter) => {
                            const count = filterCounts[filter] || 0;
                            return (
                                <Chip
                                    key={filter}
                                    label={`${filter.toUpperCase()} (${count})`}
                                    onClick={() => setFeedFilter(filter)}
                                    color={feedFilter === filter ? 'primary' : 'default'}
                                    sx={{ fontWeight: 900, fontSize: '0.65rem', borderRadius: 2 }}
                                />
                            );
                        })}
                    </Stack>

                    <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' } }} />

                    {/* Live toggle */}
                    <Stack direction="row" spacing={1} alignItems="center">
                        <Typography variant="caption" sx={{ fontWeight: 900, color: 'text.secondary', fontSize: '0.65rem', letterSpacing: '0.05em' }}>LIVE STREAM</Typography>
                        <IconButton size="small" onClick={() => setLiveFeed(!liveFeed)} color={liveFeed ? "success" : "default"} aria-label={liveFeed ? "Turn off live stream" : "Turn on live stream"}>
                            {liveFeed ? <ToggleOnIcon sx={{ fontSize: 32 }} /> : <ToggleOffIcon sx={{ fontSize: 32 }} />}
                        </IconButton>
                    </Stack>
                </Stack>
            </Stack>
            
            {/* Events List container */}
            <Box sx={{ flexGrow: 1, overflowY: 'auto', pr: 1, '&::-webkit-scrollbar': { width: '4px' }, '&::-webkit-scrollbar-thumb': { bgcolor: 'rgba(0,0,0,0.1)', borderRadius: '10px' } }}>
                <Stack spacing={2}>
                    {filteredLogs.map((log) => {
                        const status = log.processing_status || 'received';
                        let statusBullet = '🔵';
                        let statusColor = '#00c853';
                        let titleText = 'Received Ingestion';
                        
                        if (status === 'processed') {
                            statusBullet = '🟢';
                            statusColor = '#00e676';
                            titleText = 'Transaction Processed';
                        } else if (status === 'failed' || log.last_error_message) {
                            statusBullet = '🔴';
                            statusColor = '#ff005c';
                            titleText = 'Ingestion Failed';
                        } else if (status === 'retry') {
                            statusBullet = '🟠';
                            statusColor = '#feb700';
                            titleText = 'Retry Ingestion Active';
                        } else if (status === 'duplicate') {
                            statusBullet = '🟣';
                            statusColor = '#00f2ff';
                            titleText = 'Duplicate Ingestion Ignored';
                        }

                        return (
                            <Box key={log.id} sx={{ transition: 'all 0.3s' }}>
                                <Box 
                                    onClick={() => setSelectedLogId(selectedLogId === log.id ? null : log.id)}
                                    sx={{ 
                                        p: 2, 
                                        borderRadius: '16px', 
                                        bgcolor: 'white',
                                        border: '1px solid rgba(0,0,0,0.04)',
                                        cursor: 'pointer',
                                        borderLeft: `5px solid ${statusColor}`,
                                        '&:hover': { transform: 'translateX(6px)', bgcolor: 'white', borderColor: 'rgba(0,0,0,0.1)' }
                                    }}
                                >
                                    <Stack direction="row" justifyContent="space-between" alignItems="center">
                                        <Stack direction="row" spacing={2} alignItems="center">
                                            <Avatar sx={{ bgcolor: 'rgba(0,0,0,0.02)', color: 'inherit', width: 32, height: 32 }}>
                                                <Typography sx={{ fontSize: '1.1rem' }}>{statusBullet}</Typography>
                                            </Avatar>
                                            <Box>
                                                <Typography sx={{ fontSize: '0.82rem', fontWeight: 900, color: '#101221', mb: 0.2 }}>
                                                    {titleText}
                                                </Typography>
                                                <Typography sx={{ fontSize: '0.68rem', fontWeight: 800, color: 'text.secondary', opacity: 0.85 }}>
                                                    TXN: {log.payload?.transaction?.transaction_id || 'N/A'} • Source: Terminal {log.terminal_id}
                                                </Typography>
                                            </Box>
                                        </Stack>
                                        <Stack direction="row" spacing={1.5} alignItems="center">
                                            <Typography sx={{ fontSize: '0.65rem', fontWeight: 800, color: 'text.secondary' }}>
                                                {new Date(log.received_at).toLocaleTimeString()}
                                            </Typography>
                                            <IconButton size="small" aria-expanded={selectedLogId === log.id} aria-label="Toggle details view">
                                                {selectedLogId === log.id ? <KeyboardArrowUpIcon /> : <KeyboardArrowDownIcon />}
                                            </IconButton>
                                        </Stack>
                                    </Stack>
                                </Box>

                                <Collapse in={selectedLogId === log.id}>
                                    <Box sx={{ mt: 1, mb: 1, px: 2 }}>
                                        <Box className="diagnostic-payload-preview" sx={{ p: 2, bgcolor: '#f1f5f9', borderRadius: '12px', overflow: 'hidden' }}>
                                            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5, pb: 1, borderBottom: '1px solid rgba(0,0,0,0.05)' }}>
                                                <Typography sx={{ fontSize: '0.65rem', fontWeight: 900, color: '#101221', opacity: 0.5 }}>RAW INGESTION CONTEXT</Typography>
                                                <Chip label="v1.stable" size="small" variant="outlined" sx={{ height: 18, fontSize: '0.55rem', fontWeight: 800 }} />
                                            </Stack>
                                            <Typography component="pre" sx={{ 
                                                m: 0, 
                                                fontSize: '0.68rem', 
                                                fontWeight: 600, 
                                                color: '#2d1b6b',
                                                whiteSpace: 'pre-wrap',
                                                wordBreak: 'break-all',
                                                fontFamily: 'monospace'
                                            }}>
                                                {JSON.stringify({
                                                    terminal_id: log.terminal_id,
                                                    status: log.processing_status,
                                                    payload: log.payload,
                                                    error: log.last_error_message || "NONE"
                                                }, null, 2)}
                                            </Typography>
                                        </Box>
                                    </Box>
                                </Collapse>
                            </Box>
                        );
                    })}
                    
                    {filteredLogs.length === 0 && (
                        <Box sx={{ py: 12, display: 'flex', flexDirection: 'column', alignItems: 'center', opacity: 0.5 }}>
                            <Typography variant="body1" sx={{ fontWeight: 900, mb: 1, letterSpacing: '0.05em', color: '#101221' }}>
                                NO MATCHING EVENTS
                            </Typography>
                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                Try selecting another filter or verify system logs.
                            </Typography>
                        </Box>
                    )}
                </Stack>
            </Box>
        </Paper>
    );
};

export default RecentEventsTimeline;
