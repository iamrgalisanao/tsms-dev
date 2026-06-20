import { createTheme } from '@mui/material/styles';

const theme = createTheme({
    palette: {
        primary: {
            main: '#e11d2d',
            light: '#ff4d5a',
            dark: '#b31522',
        },
        secondary: {
            main: '#0a1931',
            light: '#162a4a',
            dark: '#050d1a',
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
            default: '#F8FAFC',
            paper: '#FFFFFF',
        },
    },
    custom: {
        eliteCard: {
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.05)',
            transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
        },
        shadows: {
            cardHover: '0 10px 15px -3px rgba(0, 0, 0, 0.05)',
        },
        gradients: {
            primary: 'linear-gradient(135deg, #ffffff 0%, #fff1f2 100%)',
            accent: 'linear-gradient(135deg, #ffffff 0%, #fffbeb 100%)',
            success: 'linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%)',
            danger: 'linear-gradient(135deg, #ffffff 0%, #fef2f2 100%)',
        }
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
                    borderRadius: '16px', // equivalent to rounded-2xl
                    background: '#ffffff',
                    border: '1px solid #e2e8f0',
                    boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.05)',
                    transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
                    '&:hover': {
                        boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.05)',
                        borderColor: '#cbd5e1',
                    }
                },
            },
        },
        MuiPaper: {
            styleOverrides: {
                root: {
                    borderRadius: '16px',
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
