/**
 * Berufsmesse - Micro Animations & Interactions
 * Subtile Animationen für verbesserte UX
 */

// Initialize animations when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    initScrollAnimations();
    initHoverEffects();
    initRippleEffects();
    initCounterAnimations();
    initPageTransitions();
    initTooltips();
});

/**
 * Scroll-triggered fade-in animations
 */
function initScrollAnimations() {
    const observerOptions = {
        root: null,
        rootMargin: '0px',
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                // Add stagger delay based on element index
                const delay = index * 50;
                setTimeout(() => {
                    entry.target.classList.add('animate-visible');
                }, delay);
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Observe elements with animation classes
    document.querySelectorAll('.animate-on-scroll').forEach(el => {
        el.classList.add('animate-hidden');
        observer.observe(el);
    });
}

/**
 * Enhanced hover effects for interactive elements
 */
function initHoverEffects() {
    // Card tilt effect
    document.querySelectorAll('.card-tilt').forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            const rotateX = (y - centerY) / 20;
            const rotateY = (centerX - x) / 20;
            
            card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(1.02)`;
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale(1)';
        });
    });

    // Magnetic button effect
    document.querySelectorAll('.btn-magnetic').forEach(btn => {
        btn.addEventListener('mousemove', (e) => {
            const rect = btn.getBoundingClientRect();
            const x = e.clientX - rect.left - rect.width / 2;
            const y = e.clientY - rect.top - rect.height / 2;
            
            btn.style.transform = `translate(${x * 0.2}px, ${y * 0.2}px)`;
        });
        
        btn.addEventListener('mouseleave', () => {
            btn.style.transform = 'translate(0, 0)';
        });
    });
}

/**
 * Ripple effect for buttons and clickable elements
 */
function initRippleEffects() {
    document.querySelectorAll('.ripple').forEach(element => {
        element.addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const ripple = document.createElement('span');
            
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.cssText = `
                position: absolute;
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                background: rgba(255, 255, 255, 0.4);
                border-radius: 50%;
                transform: scale(0);
                animation: ripple-animation 0.6s ease-out;
                pointer-events: none;
            `;
            
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
}

// Add ripple keyframes
const rippleStyle = document.createElement('style');
rippleStyle.textContent = `
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    .animate-hidden {
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .animate-visible {
        opacity: 1;
        transform: translateY(0);
    }
`;
document.head.appendChild(rippleStyle);

/**
 * Animated counters for statistics
 */
function initCounterAnimations() {
    const counters = document.querySelectorAll('.counter-animate');
    
    const animateCounter = (counter) => {
        const target = parseInt(counter.dataset.target || counter.textContent);
        const duration = parseInt(counter.dataset.duration || 1000);
        const start = 0;
        const startTime = performance.now();
        
        const updateCounter = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function (ease-out)
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(start + (target - start) * easeOut);
            
            counter.textContent = current;
            
            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            }
        };
        
        requestAnimationFrame(updateCounter);
    };
    
    // Use Intersection Observer to trigger animation when visible
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    counters.forEach(counter => observer.observe(counter));
}

/**
 * Smooth page transitions
 */
function initPageTransitions() {
    // Add transition class to body
    document.body.classList.add('page-transition-ready');
    
    // Intercept link clicks for smooth transitions
    document.querySelectorAll('a[href^="?"]').forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // Skip if no href or modifier key is pressed
            if (!href || e.ctrlKey || e.metaKey || e.shiftKey) return;
            
            e.preventDefault();
            
            // Fade out
            document.body.classList.add('page-transitioning');
            
            // Navigate after short delay for animation
            setTimeout(() => {
                // Use full URL to ensure proper navigation
                const currentUrl = new URL(window.location.href);
                const newUrl = new URL(href, currentUrl.origin + currentUrl.pathname);
                window.location.href = newUrl.href;
            }, 150);
        });
    });
    
    // Fade in on page load
    window.addEventListener('pageshow', () => {
        document.body.classList.remove('page-transitioning');
    });
}

// Add page transition styles
const transitionStyle = document.createElement('style');
transitionStyle.textContent = `
    .page-transition-ready {
        transition: opacity 0.2s ease;
    }
    
    .page-transitioning {
        opacity: 0;
    }
`;
document.head.appendChild(transitionStyle);

/**
 * Interactive tooltips
 */
function initTooltips() {
    document.querySelectorAll('[data-tooltip]').forEach(element => {
        let tooltip = null;
        
        element.addEventListener('mouseenter', function(e) {
            const text = this.dataset.tooltip;
            const position = this.dataset.tooltipPosition || 'top';
            
            tooltip = document.createElement('div');
            tooltip.className = `tooltip tooltip-${position}`;
            tooltip.textContent = text;
            tooltip.style.cssText = `
                position: absolute;
                padding: 6px 12px;
                background: #1f2937;
                color: white;
                font-size: 12px;
                border-radius: 6px;
                white-space: nowrap;
                z-index: 1000;
                opacity: 0;
                transform: translateY(4px);
                transition: all 0.2s ease;
                pointer-events: none;
            `;
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            
            let top, left;
            
            switch(position) {
                case 'top':
                    top = rect.top - tooltipRect.height - 8;
                    left = rect.left + (rect.width - tooltipRect.width) / 2;
                    break;
                case 'bottom':
                    top = rect.bottom + 8;
                    left = rect.left + (rect.width - tooltipRect.width) / 2;
                    break;
                case 'left':
                    top = rect.top + (rect.height - tooltipRect.height) / 2;
                    left = rect.left - tooltipRect.width - 8;
                    break;
                case 'right':
                    top = rect.top + (rect.height - tooltipRect.height) / 2;
                    left = rect.right + 8;
                    break;
            }
            
            tooltip.style.top = `${top + window.scrollY}px`;
            tooltip.style.left = `${left}px`;
            
            // Trigger animation
            requestAnimationFrame(() => {
                tooltip.style.opacity = '1';
                tooltip.style.transform = 'translateY(0)';
            });
        });
        
        element.addEventListener('mouseleave', () => {
            if (tooltip) {
                tooltip.style.opacity = '0';
                tooltip.style.transform = 'translateY(4px)';
                setTimeout(() => tooltip.remove(), 200);
            }
        });
    });
}

/**
 * Toast notification system
 */
window.showToast = function(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const colors = {
        success: '#a8e6cf',
        error: '#ffb7b2',
        warning: '#fff3b0',
        info: '#b5deff'
    };
    
    toast.innerHTML = `
        <div class="toast-icon" style="color: ${colors[type]}">
            <i class="fas ${icons[type]}"></i>
        </div>
        <div class="toast-message">${message}</div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    toast.style.cssText = `
        position: fixed;
        bottom: 24px;
        right: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        border-left: 4px solid ${colors[type]};
        transform: translateX(calc(100% + 24px));
        transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        z-index: 9999;
    `;
    
    document.body.appendChild(toast);
    
    // Trigger animation
    requestAnimationFrame(() => {
        toast.style.transform = 'translateX(0)';
    });
    
    // Auto remove
    if (duration > 0) {
        setTimeout(() => {
            toast.style.transform = 'translateX(calc(100% + 24px))';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
    
    return toast;
};

/**
 * Skeleton loading effect
 */
window.showSkeleton = function(container, count = 3) {
    container.innerHTML = '';
    
    for (let i = 0; i < count; i++) {
        const skeleton = document.createElement('div');
        skeleton.className = 'skeleton-item';
        skeleton.innerHTML = `
            <div class="skeleton skeleton-avatar"></div>
            <div class="skeleton-content">
                <div class="skeleton skeleton-title"></div>
                <div class="skeleton skeleton-text"></div>
                <div class="skeleton skeleton-text short"></div>
            </div>
        `;
        skeleton.style.cssText = `
            display: flex;
            gap: 16px;
            padding: 16px;
            margin-bottom: 16px;
        `;
        container.appendChild(skeleton);
    }
};

// Add skeleton styles
const skeletonStyle = document.createElement('style');
skeletonStyle.textContent = `
    .skeleton {
        background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s infinite;
        border-radius: 8px;
    }
    
    .skeleton-avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        flex-shrink: 0;
    }
    
    .skeleton-content {
        flex: 1;
    }
    
    .skeleton-title {
        height: 20px;
        width: 60%;
        margin-bottom: 12px;
    }
    
    .skeleton-text {
        height: 14px;
        width: 100%;
        margin-bottom: 8px;
    }
    
    .skeleton-text.short {
        width: 40%;
    }
    
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    
    .toast-icon {
        font-size: 20px;
    }
    
    .toast-message {
        flex: 1;
        font-size: 14px;
        color: #374151;
    }
    
    .toast-close {
        background: none;
        border: none;
        color: #9ca3af;
        cursor: pointer;
        padding: 4px;
        transition: color 0.2s;
    }
    
    .toast-close:hover {
        color: #6b7280;
    }
`;
document.head.appendChild(skeletonStyle);

// Export utilities
window.BerufsmesseAnimations = {
    initScrollAnimations,
    initHoverEffects,
    initRippleEffects,
    initCounterAnimations,
    initPageTransitions,
    initTooltips,
    showToast,
    showSkeleton
};
