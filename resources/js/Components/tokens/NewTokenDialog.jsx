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

const NewTokenDialog = ({ open, token, onClose, terminalName }) => {
    const [showToken, setShowToken] = useState(false);
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(token);
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
                <Alert severity="warning" sx={{ mb: 3, borderRadius: 2 }}>
                    <AlertTitle sx={{ fontWeight: 700 }}>Important Security Notice</AlertTitle>
                    Copy this token now. For security reasons, <strong>it will not be shown again</strong>.
                    Anyone with this token can authenticate as this terminal.
                </Alert>

                <Typography variant="subtitle2" sx={{ mb: 1, color: 'text.secondary', fontWeight: 600 }}>
                    Terminal: <Box component="span" sx={{ color: 'text.primary' }}>{terminalName}</Box>
                </Typography>

                <Box sx={{ mt: 2 }}>
                    <TextField
                        fullWidth
                        label="API Bearer Token"
                        value={token || ''}
                        type={showToken ? 'text' : 'password'}
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
                                    <IconButton onClick={() => setShowToken(!showToken)} edge="end" size="small">
                                        {showToken ? <VisibilityOffIcon /> : <VisibilityIcon />}
                                    </IconButton>
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
                    I have saved the token
                </Button>
            </DialogActions>
        </Dialog>
    );
};

export default NewTokenDialog;
