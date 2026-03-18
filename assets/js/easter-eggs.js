/**
 * BERUFSMESSE - Easter Eggs
 * Fun hidden features for users to discover!
 */

// ============================================================
// Retro 2002 Mode: Apply IMMEDIATELY from localStorage
// (before DOMContentLoaded to prevent flash of modern style)
// ============================================================
(function() {
    if (localStorage.getItem('retro2002') === 'on') {
        document.documentElement.classList.add('retro-2002-mode');
    }
})();

// Easter Egg State Management
const easterEggs = {
    dashboard2002: {
        clickCount: 0,
        clickTimeout: null
    },
    bouncingIcons: {
        clickCount: 0,
        clickTimeout: null,
        active: false,
        animationTimeout: null
    },
    bubbleScreensaver: {
        clickCount: 0,
        clickTimeout: null,
        enabled: false,
        bubbles: []
    }
};

/**
 * Easter Egg 1: Dashboard button - 2002 Style Mode
 * Press Dashboard button 5x in 10 seconds to toggle retro 2002 styling
 * Persists across all pages via localStorage
 */
function initDashboard2002EasterEgg() {
    const dashboardBtn = document.querySelector('a[data-page="dashboard"]');
    if (!dashboardBtn) return;

    dashboardBtn.addEventListener('click', function(e) {
        easterEggs.dashboard2002.clickCount++;

        // Clear existing timeout
        if (easterEggs.dashboard2002.clickTimeout) {
            clearTimeout(easterEggs.dashboard2002.clickTimeout);
        }

        // Reset count after 10 seconds
        easterEggs.dashboard2002.clickTimeout = setTimeout(() => {
            easterEggs.dashboard2002.clickCount = 0;
        }, 10000);

        // Trigger on 5th click
        if (easterEggs.dashboard2002.clickCount === 5) {
            toggle2002Mode();
            easterEggs.dashboard2002.clickCount = 0;
        }
    });
}

function toggle2002Mode() {
    const isActive = document.documentElement.classList.contains('retro-2002-mode');
    
    if (!isActive) {
        document.documentElement.classList.add('retro-2002-mode');
        localStorage.setItem('retro2002', 'on');
        startCursorTrail();
        addUnderConstructionBar();
        showEasterEggNotification('🕹️ Willkommen auf meiner Homepage! Gästebuch nicht vergessen!');
        console.log('🎉 2002-Modus AKTIVIERT! Optimiert für IE6 bei 800x600');
    } else {
        document.documentElement.classList.remove('retro-2002-mode');
        localStorage.setItem('retro2002', 'off');
        stopCursorTrail();
        removeUnderConstructionBar();
        showEasterEggNotification('👋 Zurück ins 21. Jahrhundert!');
        console.log('✨ 2002-Modus DEAKTIVIERT!');
    }
}

// ============================================================
// Cursor Star Trail (follows mouse in retro mode)
// ============================================================
let cursorTrailActive = false;
let cursorTrailHandler = null;

function startCursorTrail() {
    if (cursorTrailActive) return;
    cursorTrailActive = true;
    
    const symbols = ['★', '☆', '·', '✦', '⋆', '+'];
    const colors = ['#ffcc00', '#66ccff', '#ff6666', '#ccccff', '#ffffff', '#ff9900'];
    let lastX = 0, lastY = 0;
    let throttle = false;
    
    cursorTrailHandler = function(e) {
        if (throttle) return;
        
        // Only create trail if mouse moved enough
        const dx = e.clientX - lastX;
        const dy = e.clientY - lastY;
        if (Math.abs(dx) < 20 && Math.abs(dy) < 20) return;
        
        lastX = e.clientX;
        lastY = e.clientY;
        throttle = true;
        
        setTimeout(() => { throttle = false; }, 60);
        
        const star = document.createElement('div');
        star.className = 'retro-cursor-trail';
        star.textContent = symbols[Math.floor(Math.random() * symbols.length)];
        star.style.left = e.clientX + 'px';
        star.style.top = e.clientY + 'px';
        star.style.color = colors[Math.floor(Math.random() * colors.length)];
        document.body.appendChild(star);
        
        setTimeout(() => star.remove(), 800);
    };
    
    document.addEventListener('mousemove', cursorTrailHandler);
}

function stopCursorTrail() {
    cursorTrailActive = false;
    if (cursorTrailHandler) {
        document.removeEventListener('mousemove', cursorTrailHandler);
        cursorTrailHandler = null;
    }
    // Clean up remaining stars
    document.querySelectorAll('.retro-cursor-trail').forEach(el => el.remove());
}

// ============================================================
// Under Construction Bar
// ============================================================
function addUnderConstructionBar() {
    if (document.getElementById('retro-construction-bar')) return;
    
    const bar = document.createElement('div');
    bar.id = 'retro-construction-bar';
    bar.className = 'retro-under-construction';
    
    const mainEl = document.querySelector('main');
    if (mainEl) {
        mainEl.insertBefore(bar, mainEl.firstChild);
    }
}

function removeUnderConstructionBar() {
    const bar = document.getElementById('retro-construction-bar');
    if (bar) bar.remove();
}

/**
 * Easter Egg 2: Search Input - "cmd.exe taskkill"
 * Type "cmd.exe taskkill" in any search field and press Enter to "close" the site
 */
function initCmdTaskkillEasterEgg() {
    // Find all search/text inputs on the page
    const searchInputs = document.querySelectorAll('input[type="text"], input[type="search"]');
    
    searchInputs.forEach(input => {
        attachCmdTaskkillListener(input);
    });
}

function triggerTaskkill() {
    // Create fake command prompt overlay
    const overlay = document.createElement('div');
    overlay.className = 'cmd-overlay';
    overlay.innerHTML = `
        <div class="cmd-window">
            <div class="cmd-title-bar">
                <span>C:\\Windows\\System32\\cmd.exe</span>
                <button onclick="this.parentElement.parentElement.parentElement.remove()">X</button>
            </div>
            <div class="cmd-content">
                <div class="cmd-line">Microsoft Windows [Version 10.0.19041.1234]</div>
                <div class="cmd-line">(c) Microsoft Corporation. All rights reserved.</div>
                <div class="cmd-line">&nbsp;</div>
                <div class="cmd-line">C:\\Users\\Student&gt; taskkill /F /IM berufsmesse.exe</div>
                <div class="cmd-line cmd-success">ERFOLG: Der Prozess "berufsmesse.exe" wurde beendet.</div>
                <div class="cmd-line">&nbsp;</div>
                <div class="cmd-line">Weiterleitung zum Login in <span id="countdown">3</span> Sekunden...</div>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    // Countdown
    let count = 3;
    const countdownEl = document.getElementById('countdown');
    const interval = setInterval(() => {
        count--;
        if (countdownEl) countdownEl.textContent = count;
        
        if (count === 0) {
            clearInterval(interval);
            window.location.href = 'logout.php';
        }
    }, 1000);
}

/**
 * Easter Egg 3: Berufsmesse Icon - Bouncing Icons
 * Press Berufsmesse logo 5x in 10 seconds to make all icons bounce for 30 seconds
 */
function initBouncingIconsEasterEgg() {
    const logoDiv = document.querySelector('.sidebar .w-10.h-10.rounded-xl');
    if (!logoDiv) return;

    logoDiv.addEventListener('click', function(e) {
        easterEggs.bouncingIcons.clickCount++;

        // Clear existing timeout
        if (easterEggs.bouncingIcons.clickTimeout) {
            clearTimeout(easterEggs.bouncingIcons.clickTimeout);
        }

        // Reset count after 10 seconds
        easterEggs.bouncingIcons.clickTimeout = setTimeout(() => {
            easterEggs.bouncingIcons.clickCount = 0;
        }, 10000);

        // Trigger on 5th click
        if (easterEggs.bouncingIcons.clickCount === 5) {
            e.preventDefault();
            activateBouncingIcons();
            easterEggs.bouncingIcons.clickCount = 0;
        }
    });
}

function activateBouncingIcons() {
    if (easterEggs.bouncingIcons.active) return; // Already active
    
    easterEggs.bouncingIcons.active = true;
    document.body.classList.add('bouncing-icons-mode');
    
    showEasterEggNotification('🎪 Party-Modus! Die Icons drehen durch!');
    console.log('🎉 Hüpfende Icons AKTIVIERT!');
    
    // Deactivate after 30 seconds
    easterEggs.bouncingIcons.animationTimeout = setTimeout(() => {
        document.body.classList.remove('bouncing-icons-mode');
        easterEggs.bouncingIcons.active = false;
        showEasterEggNotification('😌 Party vorbei... die Icons sind müde!');
        console.log('✨ Hüpfende Icons DEAKTIVIERT!');
    }, 30000);
}

/**
 * Easter Egg 4: User Icon - Bubble Screensaver
 * Press user icon 5x in 10 seconds to toggle Windows bubble screensaver
 */
function initBubbleScreensaverEasterEgg() {
    const userAvatar = document.querySelector('.sidebar .w-10.h-10.rounded-xl.overflow-hidden');
    if (!userAvatar) return;

    userAvatar.addEventListener('click', function(e) {
        easterEggs.bubbleScreensaver.clickCount++;

        // Clear existing timeout
        if (easterEggs.bubbleScreensaver.clickTimeout) {
            clearTimeout(easterEggs.bubbleScreensaver.clickTimeout);
        }

        // Reset count after 10 seconds
        easterEggs.bubbleScreensaver.clickTimeout = setTimeout(() => {
            easterEggs.bubbleScreensaver.clickCount = 0;
        }, 10000);

        // Trigger on 5th click
        if (easterEggs.bubbleScreensaver.clickCount === 5) {
            e.preventDefault();
            toggleBubbleScreensaver();
            easterEggs.bubbleScreensaver.clickCount = 0;
        }
    });
}

function toggleBubbleScreensaver() {
    easterEggs.bubbleScreensaver.enabled = !easterEggs.bubbleScreensaver.enabled;
    
    if (easterEggs.bubbleScreensaver.enabled) {
        startBubbleScreensaver();
        showEasterEggNotification('💭 Blasen-Bildschirmschoner aktiviert! So nostalgisch!');
        console.log('🎉 Blasen-Bildschirmschoner AKTIVIERT!');
    } else {
        stopBubbleScreensaver();
        showEasterEggNotification('👋 Keine Blasen mehr!');
        console.log('✨ Blasen-Bildschirmschoner DEAKTIVIERT!');
    }
}

function startBubbleScreensaver() {
    // Create container for bubbles
    const container = document.createElement('div');
    container.id = 'bubble-screensaver';
    container.className = 'bubble-screensaver';
    document.body.appendChild(container);
    
    // Create bubbles
    const bubbleCount = 15;
    for (let i = 0; i < bubbleCount; i++) {
        createBubble(container);
    }
}

function createBubble(container) {
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    
    // Random size between 20 and 80px
    const size = Math.random() * 60 + 20;
    bubble.style.width = size + 'px';
    bubble.style.height = size + 'px';
    
    // Random starting position
    bubble.style.left = Math.random() * 100 + '%';
    bubble.style.top = Math.random() * 100 + '%';
    
    // Random color
    const hue = Math.random() * 360;
    bubble.style.background = `radial-gradient(circle at 30% 30%, 
        hsla(${hue}, 70%, 70%, 0.8), 
        hsla(${hue}, 70%, 50%, 0.6))`;
    
    // Random animation duration and delay
    const duration = Math.random() * 10 + 10; // 10-20 seconds
    const delay = Math.random() * 5; // 0-5 seconds
    bubble.style.animationDuration = duration + 's';
    bubble.style.animationDelay = delay + 's';
    
    container.appendChild(bubble);
    easterEggs.bubbleScreensaver.bubbles.push(bubble);
}

function stopBubbleScreensaver() {
    const container = document.getElementById('bubble-screensaver');
    if (container) {
        container.remove();
    }
    easterEggs.bubbleScreensaver.bubbles = [];
}

/**
 * Show notification for easter egg activation
 */
function showEasterEggNotification(message) {
    // Remove existing notification
    const existing = document.querySelector('.easter-egg-notification');
    if (existing) {
        existing.remove();
    }
    
    const notification = document.createElement('div');
    notification.className = 'easter-egg-notification';
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Remove after 4 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 4000);
}

/**
 * Initialize all easter eggs when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🥚 Easter Eggs initialisiert... Kannst du alle finden?');
    
    initDashboard2002EasterEgg();
    initCmdTaskkillEasterEgg();
    initBouncingIconsEasterEgg();
    initBubbleScreensaverEasterEgg();
    
    // If retro mode is active (from localStorage), start cursor trail & construction bar
    if (document.documentElement.classList.contains('retro-2002-mode')) {
        startCursorTrail();
        addUnderConstructionBar();
    }
});

// Also initialize when navigating between pages (since content changes dynamically)
if (window.addEventListener) {
    window.addEventListener('load', function() {
        // Re-initialize search inputs after page loads
        setTimeout(() => {
            initCmdTaskkillEasterEgg();
        }, 500);
    });
}

// Add a global reinit function that can be called by pages
window.reinitEasterEggs = function() {
    initCmdTaskkillEasterEgg();
};

// Use MutationObserver to detect when new inputs are added to the DOM
const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.addedNodes.length) {
            mutation.addedNodes.forEach(function(node) {
                // Check if the added node is an input or contains inputs
                if (node.nodeType === 1) { // Element node
                    if (node.tagName === 'INPUT' && (node.type === 'text' || node.type === 'search')) {
                        attachCmdTaskkillListener(node);
                    } else if (node.querySelectorAll) {
                        const inputs = node.querySelectorAll('input[type="text"], input[type="search"]');
                        inputs.forEach(input => attachCmdTaskkillListener(input));
                    }
                }
            });
        }
    });
});

// Start observing the document body for changes
if (document.body) {
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

// Helper function to attach the listener to a single input
function attachCmdTaskkillListener(input) {
    // Check if listener already attached
    if (input.dataset.easterEggAttached) return;
    
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const value = this.value.trim().toLowerCase();
            if (value === 'cmd.exe taskkill') {
                e.preventDefault();
                triggerTaskkill();
            }
        }
    });
    
    input.dataset.easterEggAttached = 'true';
}
