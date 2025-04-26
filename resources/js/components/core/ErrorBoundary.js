import React from 'react';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    // You can log the error to an error reporting service here
    console.error('ErrorBoundary caught an error:', error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      // Render error UI
      return React.createElement(
        'div',
        {
          style: {
            padding: '20px',
            margin: '20px',
            border: '1px solid #dc3545',
            borderRadius: '4px',
            backgroundColor: '#fff5f5'
          }
        },
        React.createElement(
          'h2',
          { style: { color: '#dc3545', marginBottom: '10px' } },
          'Something went wrong'
        ),
        React.createElement(
          'p',
          { style: { color: '#666' } },
          this.state.error?.message || 'An unexpected error occurred'
        ),
        React.createElement(
          'button',
          {
            onClick: () => {
              this.setState({ hasError: false, error: null });
              window.location.reload();
            },
            style: {
              marginTop: '10px',
              padding: '8px 16px',
              backgroundColor: '#dc3545',
              color: 'white',
              border: 'none',
              borderRadius: '4px',
              cursor: 'pointer'
            }
          },
          'Try again'
        )
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
