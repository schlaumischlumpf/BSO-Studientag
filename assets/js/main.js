/**
 * BERUFSMESSE - Modern JavaScript Enhancements
 * Animations, interactions, and utility functions
 */

// Smooth scroll behavior
document.documentElement.style.scrollBehavior = 'smooth';

// Page load animations
document.addEventListener('DOMContentLoaded', () => {
    // Animate cards on load
    animateOnScroll();
    
    // Initialize tooltips
    initializeTooltips();
    
    // Add ripple effect to buttons
    addRippleEffect();
    
    // Stats counter animation
    animateCounters();
});

/**
 * Animate elements when they come into view
 */
function animateOnScroll() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '0';
                entry.target.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    entry.target.style.transition = 'all 0.6s ease-out';
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, entry.target.dataset.delay || 0);
                
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });

    // Observe all cards
    document.querySelectorAll('.card, .stat-card').forEach((el, index) => {
        el.dataset.delay = index * 100;
        observer.observe(el);
    });
}

/**
 * Animate number counters
 */
function animateCounters() {
    const counters = document.querySelectorAll('.stat-value');
    
    counters.forEach(counter => {
        const target = parseInt(counter.textContent);
        if (isNaN(target)) return;
        
        const duration = 2000;
        const increment = target / (duration / 16);
        let current = 0;
        
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                counter.textContent = target;
                clearInterval(timer);
            } else {
                counter.textContent = Math.floor(current);
            }
        }, 16);
    });
}

/**
 * Add ripple effect to buttons
 */
function addRippleEffect() {
    const buttons = document.querySelectorAll('.btn');
    
    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const ripple = document.createElement('span');
            ripple.style.cssText = `
                position: absolute;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                transform: translate(-50%, -50%) scale(0);
                animation: ripple-animation 0.6s ease-out;
                pointer-events: none;
                left: ${x}px;
                top: ${y}px;
            `;
            
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
    
    // Add ripple animation to stylesheet if not exists
    if (!document.querySelector('#ripple-style')) {
        const style = document.createElement('style');
        style.id = 'ripple-style';
        style.textContent = `
            @keyframes ripple-animation {
                to {
                    transform: translate(-50%, -50%) scale(20);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    }
}

/**
 * Initialize tooltips
 */
function initializeTooltips() {
    const elementsWithTitle = document.querySelectorAll('[title]');
    
    elementsWithTitle.forEach(el => {
        const title = el.getAttribute('title');
        if (!title) return;
        
        // Remove default browser tooltip
        el.removeAttribute('title');
        el.dataset.tooltip = title;
        
        el.addEventListener('mouseenter', showTooltip);
        el.addEventListener('mouseleave', hideTooltip);
    });
}

function showTooltip(e) {
    const text = e.target.dataset.tooltip;
    if (!text) return;
    
    const tooltip = document.createElement('div');
    tooltip.className = 'custom-tooltip';
    tooltip.textContent = text;
    tooltip.style.cssText = `
        position: fixed;
        background: rgba(0, 0, 0, 0.9);
        color: white;
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        pointer-events: none;
        z-index: 10000;
        animation: fadeIn 0.2s ease-out;
    `;
    
    document.body.appendChild(tooltip);
    
    const rect = e.target.getBoundingClientRect();
    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
    tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';
    
    e.target.tooltipElement = tooltip;
}

function hideTooltip(e) {
    if (e.target.tooltipElement) {
        e.target.tooltipElement.remove();
        delete e.target.tooltipElement;
    }
}

/**
 * Toast notification system
 */
const Toast = {
    show: function(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type}`;
        toast.style.cssText = `
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 10000;
            min-width: 300px;
            animation: slideIn 0.3s ease-out;
        `;
        
        const icon = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        }[type] || 'fa-info-circle';
        
        toast.innerHTML = `
            <i class="fas ${icon} alert-icon"></i>
            <div class="flex-1">${message}</div>
            <button onclick="this.parentElement.remove()" class="ml-3 text-current opacity-70 hover:opacity-100">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.body.appendChild(toast);
        
        if (duration > 0) {
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
    },
    
    success: function(message, duration) {
        this.show(message, 'success', duration);
    },
    
    error: function(message, duration) {
        this.show(message, 'danger', duration);
    },
    
    warning: function(message, duration) {
        this.show(message, 'warning', duration);
    },
    
    info: function(message, duration) {
        this.show(message, 'info', duration);
    }
};

// Make Toast globally available
window.Toast = Toast;

/**
 * Smooth form validation feedback
 */
document.addEventListener('invalid', (e) => {
    e.preventDefault();
    const input = e.target;
    
    input.classList.add('border-red-500');
    input.style.animation = 'shake 0.3s ease-out';
    
    setTimeout(() => {
        input.style.animation = '';
    }, 300);
}, true);

// Add shake animation
if (!document.querySelector('#shake-style')) {
    const style = document.createElement('style');
    style.id = 'shake-style';
    style.textContent = `
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }
    `;
    document.head.appendChild(style);
}

/**
 * Confirm dialogs with custom styling
 */
window.confirmAction = function(message, callback) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    
    overlay.innerHTML = `
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p>${message}</p>
            </div>
            <div class="modal-footer">
                <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-ghost">
                    Cancel
                </button>
                <button onclick="confirmCallback(); this.closest('.modal-overlay').remove()" class="btn btn-danger">
                    <i class="fas fa-check mr-2"></i>Confirm
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    window.confirmCallback = callback;
    
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.remove();
        }
    });
};

/**
 * Loading indicator
 */
window.showLoading = function(message = 'Loading...') {
    const existing = document.querySelector('#loading-overlay');
    if (existing) return;
    
    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        animation: fadeIn 0.2s ease-out;
    `;
    
    overlay.innerHTML = `
        <div class="glass-effect" style="padding: 2rem; border-radius: 1rem; text-align: center;">
            <i class="fas fa-spinner loading-spinner text-4xl text-blue-500 mb-3"></i>
            <p class="text-white font-semibold">${message}</p>
        </div>
    `;
    
    document.body.appendChild(overlay);
};

window.hideLoading = function() {
    const overlay = document.querySelector('#loading-overlay');
    if (overlay) {
        overlay.style.animation = 'fadeOut 0.2s ease-out';
        setTimeout(() => overlay.remove(), 200);
    }
};

// Add fadeOut animation
if (!document.querySelector('#fade-style')) {
    const style = document.createElement('style');
    style.id = 'fade-style';
    style.textContent = `
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}

/**
 * Auto-hide alerts after 5 seconds
 */
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
});

console.log('%c🎨 Berufsmesse Design System Loaded', 'color: #3b82f6; font-size: 16px; font-weight: bold;');
