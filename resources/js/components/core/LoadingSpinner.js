import React from 'react';

const LoadingSpinner = ({ size = 'medium', center = true }) => {
  const sizes = {
    small: { height: '16px', width: '16px' },
    medium: { height: '24px', width: '24px' },
    large: { height: '32px', width: '32px' }
  };

  const containerStyle = center ? {
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'center',
    padding: '20px'
  } : {};

  return React.createElement(
    'div',
    { style: containerStyle },
    React.createElement(
      'svg',
      {
        style: {
          ...sizes[size],
          animation: 'spin 1s linear infinite',
          color: '#0066cc'
        },
        xmlns: 'http://www.w3.org/2000/svg',
        fill: 'none',
        viewBox: '0 0 24 24'
      },
      React.createElement('circle', {
        style: { opacity: 0.25 },
        cx: '12',
        cy: '12',
        r: '10',
        stroke: 'currentColor',
        strokeWidth: '4'
      }),
      React.createElement('path', {
        style: { opacity: 0.75 },
        fill: 'currentColor',
        d: 'M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z'
      })
    )
  );
};

export default LoadingSpinner;
