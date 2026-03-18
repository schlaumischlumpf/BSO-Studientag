/**
 * Berufsmesse - Tailwind CSS Custom Configuration
 * Pastel Color Extensions for Tailwind
 */

// Extend Tailwind with custom pastel colors
tailwind.config = {
    theme: {
        extend: {
            colors: {
                // Pastel Palette
                pastel: {
                    mint: {
                        DEFAULT: '#a8e6cf',
                        light: '#d4f5e4',
                        dark: '#6bc4a6',
                    },
                    lavender: {
                        DEFAULT: '#c3b1e1',
                        light: '#e8dff5',
                        dark: '#9b7fc7',
                    },
                    peach: {
                        DEFAULT: '#ffb7b2',
                        light: '#ffdad8',
                        dark: '#e8918a',
                    },
                    sky: {
                        DEFAULT: '#b5deff',
                        light: '#dceeff',
                        dark: '#7fc4ff',
                    },
                    butter: {
                        DEFAULT: '#fff3b0',
                        light: '#fff9d9',
                        dark: '#f5e080',
                    },
                    rose: {
                        DEFAULT: '#ffc8dd',
                        light: '#ffe4ee',
                        dark: '#ffafc7',
                    }
                }
            },
            animation: {
                'fade-in': 'fadeIn 0.3s ease-out',
                'fade-in-up': 'fadeInUp 0.4s ease-out',
                'fade-in-down': 'fadeInDown 0.4s ease-out',
                'slide-in-left': 'slideInLeft 0.3s ease-out',
                'slide-in-right': 'slideInRight 0.3s ease-out',
                'scale-in': 'scaleIn 0.3s ease-out',
                'bounce-soft': 'bounceSoft 1s ease-in-out infinite',
                'float': 'float 3s ease-in-out infinite',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                fadeInUp: {
                    '0%': { opacity: '0', transform: 'translateY(20px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                fadeInDown: {
                    '0%': { opacity: '0', transform: 'translateY(-20px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                slideInLeft: {
                    '0%': { opacity: '0', transform: 'translateX(-20px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
                slideInRight: {
                    '0%': { opacity: '0', transform: 'translateX(20px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
                scaleIn: {
                    '0%': { opacity: '0', transform: 'scale(0.95)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                bounceSoft: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-5px)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0px)' },
                    '50%': { transform: 'translateY(-10px)' },
                },
            },
            boxShadow: {
                'glow-mint': '0 0 20px rgba(168, 230, 207, 0.4)',
                'glow-lavender': '0 0 20px rgba(195, 177, 225, 0.4)',
                'glow-peach': '0 0 20px rgba(255, 183, 178, 0.4)',
                'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
            },
            borderRadius: {
                '4xl': '2rem',
            },
            transitionTimingFunction: {
                'bounce-in': 'cubic-bezier(0.68, -0.55, 0.265, 1.55)',
            }
        }
    }
};

// Apply configuration if Tailwind CDN is loaded
if (typeof tailwind !== 'undefined') {
    console.log('Tailwind pastel theme loaded');
}
