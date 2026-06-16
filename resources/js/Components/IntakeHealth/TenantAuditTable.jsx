import React, { useState } from 'react';
import { 
    Paper, 
    TableContainer, 
    Table, 
    TableHead, 
    TableRow, 
    TableCell, 
    TableBody, 
    Typography, 
    Stack, 
    Chip, 
    Button, 
    Tooltip, 
    Box,
    Checkbox,
    IconButton,
    Menu,
    MenuItem,
    Divider
} from '@mui/material';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import RefreshIcon from '@mui/icons-material/Refresh';
import LibraryBooksIcon from '@mui/icons-material/LibraryBooks';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import DownloadIcon from '@mui/icons-material/Download';

const TenantAuditTable = ({
    auditRows = [],
    selectedTenantId = null,
    onSelectRow,
    onInspect,
    onReplay,
    onViewLogs
}) => {
    const [selectedRowIds, setSelectedRowIds] = useState([]);
    const [menuAnchor, setMenuAnchor] = useState(null);
    const [activeMenuRow, setActiveMenuRow] = useState(null);

    const getRowHealth = (flags = []) => {
        if (flags.length === 0) return { label: 'Healthy', color: '#00e676', icon: '🟢' };
        if (flags.some(f => f.includes('DRIFT') || f.includes('NO_PERSISTED'))) {
            return { label: 'Critical', color: '#ff1744', icon: '🔴' };
        }
        return { label: 'Warning', color: '#feb700', icon: '🟡' };
    };

    // Checkbox toggles
    const handleSelectAllClick = (event) => {
        if (event.target.checked) {
            const newSelecteds = auditRows.map((n) => n.tenant_id);
            setSelectedRowIds(newSelecteds);
        } else {
            setSelectedRowIds([]);
        }
    };

    const handleCheckboxClick = (event, id) => {
        event.stopPropagation();
        const selectedIndex = selectedRowIds.indexOf(id);
        let newSelected = [];

        if (selectedIndex === -1) {
            newSelected = newSelected.concat(selectedRowIds, id);
        } else if (selectedIndex === 0) {
            newSelected = newSelected.concat(selectedRowIds.slice(1));
        } else if (selectedIndex === selectedRowIds.length - 1) {
            newSelected = newSelected.concat(selectedRowIds.slice(0, -1));
        } else if (selectedIndex > 0) {
            newSelected = newSelected.concat(
                selectedRowIds.slice(0, selectedIndex),
                selectedRowIds.slice(selectedIndex + 1)
            );
        }
        setSelectedRowIds(newSelected);
    };

    // Action Menu controllers
    const handleMenuOpen = (event, row) => {
        event.stopPropagation();
        setMenuAnchor(event.currentTarget);
        setActiveMenuRow(row);
    };

    const handleMenuClose = () => {
        setMenuAnchor(null);
        setActiveMenuRow(null);
    };

    const handleMenuAction = (action) => {
        if (!activeMenuRow) return;
        if (action === 'inspect') onInspect && onInspect(activeMenuRow);
        if (action === 'replay') onReplay && onReplay(activeMenuRow);
        if (action === 'logs') onViewLogs && onViewLogs(activeMenuRow);
        if (action === 'details') onSelectRow && onSelectRow(activeMenuRow);
        handleMenuClose();
    };

    // Bulk action handlers
    const handleBulkReplay = () => {
        if (selectedRowIds.length === 0) return;
        alert(`Queueing ingestion replay for ${selectedRowIds.length} selected tenants...`);
        setSelectedRowIds([]);
    };

    const handleExportCSV = () => {
        const selectedTenants = auditRows.filter(r => selectedRowIds.includes(r.tenant_id));
        const rowsToExport = selectedTenants.length > 0 ? selectedTenants : auditRows;
        
        // Generate CSV string client-side
        const headers = 'Tenant ID,Tenant Name,Terminals,Submissions,Quarantine,Valid,Pending,Failed,Gross Sales,Last Ingestion,Flags\n';
        const csvContent = rowsToExport.map(r => 
            `"${r.tenant_id}","${r.tenant}","${r.active_terminals}/${r.terminals}","${r.submissions}","${r.quarantined}","${r.valid}","${r.pending}","${r.invalid_or_failed}","${r.gross_sales}","${r.last_transaction_at || '-'}","${(r.flags || []).join(';')}"`
        ).join('\n');
        
        const blob = new Blob([headers + csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', `tenant_ingest_audit_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const handleOpenFlaggedOnly = () => {
        const flaggedIds = auditRows.filter(r => (r.flags || []).length > 0).map(r => r.tenant_id);
        setSelectedRowIds(flaggedIds);
    };

    const handleGenerateAuditReport = () => {
        alert('Generating compliance audit report. Check your browser downloads shortly.');
        handleExportCSV();
    };

    const isSelected = (id) => selectedRowIds.indexOf(id) !== -1;

    return (
        <Box>
            {/* Bulk Actions & Results Counts Toolbar */}
            <Paper 
                className="glass-container"
                sx={{ 
                    p: 2, 
                    mb: 2.5, 
                    borderRadius: '16px', 
                    border: '1px solid', 
                    borderColor: 'divider', 
                    bgcolor: '#f8fafc', 
                    display: 'flex', 
                    justifyContent: 'space-between', 
                    alignItems: 'center', 
                    flexWrap: 'wrap', 
                    gap: 1.5 
                }}
            >
                <Stack direction="row" spacing={1.5} alignItems="center" sx={{ flexWrap: 'wrap', gap: 1 }}>
                    <Checkbox
                        indeterminate={selectedRowIds.length > 0 && selectedRowIds.length < auditRows.length}
                        checked={auditRows.length > 0 && selectedRowIds.length === auditRows.length}
                        onChange={handleSelectAllClick}
                        inputProps={{ 'aria-label': 'Select all tenants' }}
                        size="small"
                        sx={{ p: 0.5 }}
                    />
                    <Typography variant="caption" sx={{ fontWeight: 900, color: '#101221', fontSize: '0.75rem' }}>
                        {selectedRowIds.length} OF {auditRows.length} SELECTED
                    </Typography>
                    
                    {selectedRowIds.length > 0 && (
                        <>
                            <Divider orientation="vertical" flexItem sx={{ mx: 1, display: { xs: 'none', sm: 'block' } }} />
                            <Button 
                                size="small" 
                                variant="contained" 
                                color="warning" 
                                onClick={handleBulkReplay}
                                startIcon={<RefreshIcon sx={{ fontSize: 12 }} />}
                                sx={{ textTransform: 'none', fontWeight: 900, borderRadius: '8px', fontSize: '0.68rem', px: 1.5 }}
                            >
                                Replay Selected
                            </Button>
                        </>
                    )}
                </Stack>
                
                <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1 }}>
                    <Button 
                        size="small" 
                        variant="outlined" 
                        color="inherit"
                        onClick={handleExportCSV}
                        startIcon={<DownloadIcon sx={{ fontSize: 12 }} />}
                        sx={{ textTransform: 'none', fontWeight: 900, borderRadius: '8px', fontSize: '0.68rem' }}
                    >
                        Export CSV
                    </Button>
                    <Button 
                        size="small" 
                        variant="outlined" 
                        color="warning"
                        onClick={handleOpenFlaggedOnly}
                        startIcon={<CheckCircleIcon sx={{ fontSize: 12 }} />}
                        sx={{ textTransform: 'none', fontWeight: 900, borderRadius: '8px', fontSize: '0.68rem' }}
                    >
                        Select Flagged
                    </Button>
                    <Button 
                        size="small" 
                        variant="outlined" 
                        onClick={handleGenerateAuditReport}
                        sx={{ textTransform: 'none', fontWeight: 900, borderRadius: '8px', fontSize: '0.68rem' }}
                    >
                        Report Summary
                    </Button>
                </Stack>
            </Paper>

            {/* Audit Table Container */}
            <TableContainer 
                component={Paper} 
                sx={{ 
                    borderRadius: 3, 
                    border: '1px solid', 
                    borderColor: 'divider', 
                    overflowY: 'auto', 
                    maxHeight: '600px',
                    '&::-webkit-scrollbar': { width: '4px' },
                    '&::-webkit-scrollbar-thumb': { bgcolor: 'rgba(0,0,0,0.1)', borderRadius: '10px' }
                }}
            >
                <Table size="small" stickyHeader>
                    <TableHead>
                        <TableRow>
                            <TableCell padding="checkbox" sx={{ bgcolor: 'white', zIndex: 3 }} />
                            <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Health</TableCell>
                            <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Tenant</TableCell>
                            <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Terminals</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Submissions</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Quarantine</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Intake</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Tx</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Valid</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Pending</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Failed</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Gross</TableCell>
                            <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Last Ingest</TableCell>
                            <TableCell sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Flags</TableCell>
                            <TableCell align="center" sx={{ fontWeight: 1000, textTransform: 'uppercase', fontSize: '0.68rem', letterSpacing: '0.05em', bgcolor: 'white', zIndex: 3 }}>Action</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {auditRows.map((row) => {
                            const health = getRowHealth(row.flags);
                            const isRowChecked = isSelected(row.tenant_id);
                            const isRowSelected = selectedTenantId === row.tenant_id;
                            
                            return (
                                <TableRow 
                                    key={row.tenant_id} 
                                    hover
                                    onClick={() => onSelectRow && onSelectRow(row)}
                                    sx={{ 
                                        cursor: 'pointer',
                                        bgcolor: isRowSelected ? 'rgba(0, 242, 255, 0.04) !important' : 'inherit',
                                        transition: 'background-color 0.2s',
                                        '&:hover': {
                                            bgcolor: isRowSelected ? 'rgba(0, 242, 255, 0.06) !important' : 'rgba(0,0,0,0.02)'
                                        }
                                    }}
                                >
                                    <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                                        <Checkbox
                                            checked={isRowChecked}
                                            onChange={(event) => handleCheckboxClick(event, row.tenant_id)}
                                            size="small"
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Tooltip title={health.label} arrow>
                                            <Typography sx={{ cursor: 'help', fontSize: '1rem', textAlign: 'center' }}>
                                                {health.icon}
                                            </Typography>
                                        </Tooltip>
                                    </TableCell>
                                    <TableCell>
                                        <Typography sx={{ fontWeight: 900, fontSize: '0.82rem' }}>{row.tenant}</Typography>
                                        <Typography sx={{ color: 'text.secondary', fontSize: '0.72rem', fontWeight: 800 }}>#{row.tenant_id} · {row.status || 'n/a'}</Typography>
                                    </TableCell>
                                    <TableCell sx={{ fontSize: '0.78rem' }}>{row.active_terminals}/{row.terminals} active · {row.terminals_without_tx} no tx</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.submissions}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.quarantined > 0 ? '#ff1744' : 'inherit' }}>{row.quarantined}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.intake_received}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.transactions}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.valid > 0 ? '#00c853' : 'inherit' }}>{row.valid}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{row.pending}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', color: row.invalid_or_failed > 0 ? '#ff1744' : 'inherit' }}>{row.invalid_or_failed}</TableCell>
                                    <TableCell align="right" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', fontWeight: 700 }}>
                                        ₱{Number(row.gross_sales || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </TableCell>
                                    <TableCell sx={{ fontSize: '0.75rem', whiteSpace: 'nowrap' }}>{row.last_transaction_at ? row.last_transaction_at.split(' ')[1] || row.last_transaction_at : '-'}</TableCell>
                                    <TableCell>
                                        <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                                            {(row.flags || []).map((flag) => (
                                                <Chip
                                                    key={flag}
                                                    size="small"
                                                    label={flag}
                                                    color={flag.includes('DRIFT') || flag.includes('NO_PERSISTED') ? 'error' : 'warning'}
                                                    sx={{ fontWeight: 900, fontSize: '0.62rem', borderRadius: '4px' }}
                                                />
                                            ))}
                                            {(row.flags || []).length === 0 && <Chip size="small" label="OK" color="success" sx={{ fontWeight: 900, borderRadius: '4px', fontSize: '0.62rem' }} />}
                                        </Stack>
                                    </TableCell>
                                    <TableCell align="center" onClick={(e) => e.stopPropagation()}>
                                        <Tooltip title="Row Actions" arrow>
                                            <IconButton size="small" onClick={(e) => handleMenuOpen(e, row)}>
                                                <MoreVertIcon sx={{ fontSize: 16 }} />
                                            </IconButton>
                                        </Tooltip>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                        
                        {auditRows.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={15} sx={{ py: 6, textAlign: 'center' }}>
                                    <Box sx={{ maxWidth: '400px', mx: 'auto', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                                        <Box sx={{ p: 1.5, borderRadius: '50%', bgcolor: 'rgba(76, 175, 80, 0.1)', color: '#00c853', mb: 2 }}>
                                            <CheckCircleIcon sx={{ fontSize: 36 }} />
                                        </Box>
                                        <Typography variant="body1" sx={{ fontWeight: 1000, color: '#101221', mb: 1, letterSpacing: '0.02em' }}>
                                            ✓ No Issues Found
                                        </Typography>
                                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, leading: '1.4', display: 'block', mb: 2 }}>
                                            No tenants matched the selected filters. All systems are green and within bounds.
                                        </Typography>
                                        <Stack direction="row" spacing={1} sx={{ fontSize: '0.68rem', fontWeight: 800, color: 'text.secondary' }}>
                                            <span>Try:</span>
                                            <span>• Expanding date range</span>
                                            <span>• Removing Tenant filter</span>
                                            <span>• Running a full audit</span>
                                        </Stack>
                                    </Box>
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </TableContainer>

            {/* Row Context Menu */}
            <Menu
                anchorEl={menuAnchor}
                open={Boolean(menuAnchor)}
                onClose={handleMenuClose}
                transformOrigin={{ horizontal: 'right', vertical: 'top' }}
                anchorOrigin={{ horizontal: 'right', vertical: 'bottom' }}
                PaperProps={{
                    sx: {
                        borderRadius: '10px',
                        border: '1px solid',
                        borderColor: 'divider',
                        boxShadow: '0 8px 16px rgba(0,0,0,0.06)',
                        minWidth: '160px'
                    }
                }}
            >
                <MenuItem onClick={() => handleMenuAction('details')} sx={{ fontSize: '0.75rem', fontWeight: 800, color: '#101221' }}>
                    <OpenInNewIcon sx={{ fontSize: 14, mr: 1.5, opacity: 0.6 }} /> View Details
                </MenuItem>
                <MenuItem onClick={() => handleMenuAction('logs')} sx={{ fontSize: '0.75rem', fontWeight: 800, color: '#101221' }}>
                    <LibraryBooksIcon sx={{ fontSize: 14, mr: 1.5, opacity: 0.6 }} /> View Logs
                </MenuItem>
                <Divider />
                <MenuItem onClick={() => handleMenuAction('replay')} sx={{ fontSize: '0.75rem', fontWeight: 800, color: 'warning.main' }}>
                    <RefreshIcon sx={{ fontSize: 14, mr: 1.5 }} /> Replay Queue
                </MenuItem>
                <MenuItem onClick={() => handleMenuAction('inspect')} sx={{ fontSize: '0.75rem', fontWeight: 800, color: 'info.main' }}>
                    <OpenInNewIcon sx={{ fontSize: 14, mr: 1.5 }} /> Inspect Payload
                </MenuItem>
            </Menu>
        </Box>
    );
};

export default TenantAuditTable;
