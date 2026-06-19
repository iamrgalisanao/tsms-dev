import { createTheme } from '@mui/material/styles';

const theme = createTheme({
    palette: {
        primary: {
            main: '#1D439B',
            light: '#4169c1',
            dark: '#142f6e',
        },
        secondary: {
            main: '#EB342E',
        },
        error: {
            main: '#EF4444',
            light: 'rgba(239,68,68,0.08)',
        },
        warning: {
            main: '#F59E0B',
            light: 'rgba(245,158,11,0.08)',
        },
        success: {
            main: '#10B981',
            light: 'rgba(16,185,129,0.08)',
        },
        info: {
            main: '#3B82F6',
        },
        background: {
            default: '#F9FAFB',
            paper: '#FFFFFF',
        },
    },
    typography: {
        fontFamily: [
            'Inter',
            '"Helvetica Neue"',
            'Helvetica',
            'Arial',
            'sans-serif',
        ].join(','),
        h1: {
            fontSize: '72px',
            fontWeight: 900,
            letterSpacing: '-0.05em',
        },
        h2: {
            fontSize: '22px',
            fontWeight: 800,
            letterSpacing: '-0.02em',
        },
        body1: {
            fontSize: '16px',
        },
    },
    shape: {
        borderRadius: 12,
    },
    components: {
        MuiButton: {
            styleOverrides: {
                root: {
                    borderRadius: '12px',
                    textTransform: 'none',
                    fontWeight: 700,
                    fontSize: '14px',
                    padding: '10px 20px',
                },
                sizeLarge: {
                    padding: '12px 24px',
                    fontSize: '15px',
                },
            },
        },
        MuiCard: {
            styleOverrides: {
                root: {
                    borderRadius: '24px',
                    boxShadow: '0 4px 20px 0 rgba(0,0,0,0.05)',
                },
            },
        },
        MuiPaper: {
            styleOverrides: {
                root: {
                    borderRadius: '24px',
                },
            },
        },
        MuiChip: {
            styleOverrides: {
                root: {
                    borderRadius: '6px',
                    fontWeight: 800,
                },
            },
        },
    },
});

export default theme;
