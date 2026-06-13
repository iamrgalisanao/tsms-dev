import { createTheme } from '@mui/material/styles';

const theme = createTheme({
    palette: {
        primary: {
            main: '#1D439B', // PITX Blue
        },
        secondary: {
            main: '#EB342E', // PITX Red
        },
        error: {
            main: '#EB342E',
        },
        background: {
            default: '#F9FAFB',
        },
    },
    typography: {
        fontFamily: [
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
            fontSize: '24px',
            fontWeight: 800,
            letterSpacing: '-0.02em',
        },
        body1: {
            fontSize: '18px',
        },
    },
    components: {
        MuiButton: {
            styleOverrides: {
                root: {
                    borderRadius: '12px',
                    textTransform: 'none',
                    fontWeight: 700,
                    fontSize: '18px',
                    padding: '12px 24px',
                },
            },
        },
        MuiCard: {
            styleOverrides: {
                root: {
                    borderRadius: '32px',
                    boxShadow: '0 4px 20px 0 rgba(0,0,0,0.05)',
                },
            },
        },
        MuiPaper: {
            styleOverrides: {
                root: {
                    borderRadius: '32px',
                },
            },
        },
    },
});

export default theme;
