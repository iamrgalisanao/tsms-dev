import React, { useState } from 'react';
import {
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button,
    Typography,
    Box,
    TextField,
    InputAdornment,
    IconButton,
    Alert,
    AlertTitle
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import KeyIcon from '@mui/icons-material/Key';

const NewTokenDialog = ({ open, token, message, onClose, terminalName }) => {
    const [showToken, setShowToken] = useState(false);
    const [copied, setCopied] = useState(false);
    const hasToken = Boolean(token);
    const displayValue = hasToken ? token : (message || '');

    const handleCopy = () => {
        navigator.clipboard.writeText(displayValue);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Dialog open={open} maxWidth="sm" fullWidth onClose={onClose}>
            <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1.5, fontWeight: 700 }}>
                <KeyIcon color="success" />
                New API Bearer Token
            </DialogTitle>
            <DialogContent dividers>
                <Alert severity={hasToken ? 'warning' : 'info'} sx={{ mb: 3, borderRadius: 2 }}>
                    <AlertTitle sx={{ fontWeight: 700 }}>
                        {hasToken ? 'Important Security Notice' : 'Token Request Required'}
                    </AlertTitle>
                    {hasToken ? (
                        <>
                            Copy this token now. For security reasons, <strong>it will not be shown again</strong>.
                            Anyone with this token can authenticate as this terminal.
                        </>
                    ) : (
                        message
                    )}
                </Alert>

                <Typography variant="subtitle2" sx={{ mb: 1, color: 'text.secondary', fontWeight: 600 }}>
                    Terminal: <Box component="span" sx={{ color: 'text.primary' }}>{terminalName}</Box>
                </Typography>

                <Box sx={{ mt: 2 }}>
                    <TextField
                        fullWidth
                        label={hasToken ? 'API Bearer Token' : 'Message'}
                        value={displayValue}
                        type={hasToken && !showToken ? 'password' : 'text'}
                        variant="outlined"
                        InputProps={{
                            readOnly: true,
                            sx: {
                                fontFamily: 'monospace',
                                bgcolor: 'grey.50',
                                fontSize: '0.95rem',
                                borderRadius: 2
                            },
                            endAdornment: (
                                <InputAdornment position="end">
                                    {hasToken && (
                                        <IconButton onClick={() => setShowToken(!showToken)} edge="end" size="small">
                                            {showToken ? <VisibilityOffIcon /> : <VisibilityIcon />}
                                        </IconButton>
                                    )}
                                    <IconButton onClick={handleCopy} edge="end" size="small" color={copied ? "success" : "primary"}>
                                        <ContentCopyIcon />
                                    </IconButton>
                                </InputAdornment>
                            )
                        }}
                    />
                    {copied && (
                        <Typography variant="caption" color="success.main" sx={{ mt: 1, display: 'block', fontWeight: 600 }}>
                            Token copied to clipboard!
                        </Typography>
                    )}
                </Box>
            </DialogContent>
            <DialogActions sx={{ p: 2.5 }}>
                <Button onClick={onClose} variant="contained" sx={{ borderRadius: 2, px: 4, fontWeight: 700 }}>
                    {hasToken ? 'I have saved the token' : 'Close'}
                </Button>
            </DialogActions>
        </Dialog>
    );
};

export default NewTokenDialog;
