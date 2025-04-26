import React from 'react';

const Button = ({ children, onClick, variant = 'primary', disabled = false, type = 'button' }) => {
  const baseStyles = {
    padding: '8px 16px',
    borderRadius: '4px',
    cursor: 'pointer',
    border: 'none',
    fontSize: '14px',
    fontWeight: '500',
  };

  const variants = {
    primary: {
      backgroundColor: '#0066cc',
      color: 'white',
    },
    secondary: {
      backgroundColor: '#f0f0f0',
      color: '#333',
    },
    danger: {
      backgroundColor: '#dc3545',
      color: 'white',
    }
  };

  const style = {
    ...baseStyles,
    ...variants[variant],
    opacity: disabled ? 0.7 : 1,
    cursor: disabled ? 'not-allowed' : 'pointer',
  };

  return React.createElement(
    'button',
    {
      style,
      onClick,
      disabled,
      type
    },
    children
  );
};

export default Button;
