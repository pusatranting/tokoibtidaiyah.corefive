/**
 * Mobile-Friendly Utilities
 * Enhancements untuk pengalaman mobile yang lebih baik
 */

// =============================================
// VIEWPORT DETECTION
// =============================================
const ViewportHelper = {
    isMobile: () => window.innerWidth <= 640,
    isTablet: () => window.innerWidth > 640 && window.innerWidth <= 1024,
    isDesktop: () => window.innerWidth > 1024,
    
    // Detect if touch device
    isTouchDevice: () => {
        return (('ontouchstart' in window) ||
                (navigator.maxTouchPoints > 0) ||
                (navigator.msMaxTouchPoints > 0));
    },
    
    // Detect device orientation
    isPortrait: () => window.innerHeight > window.innerWidth,
    isLandscape: () => window.innerWidth > window.innerHeight,
    
    // Get safe area insets (for notch phones)
    getSafeAreaInsets: () => {
        return {
            top: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--safe-area-inset-top') || 0),
            right: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--safe-area-inset-right') || 0),
            bottom: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--safe-area-inset-bottom') || 0),
            left: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--safe-area-inset-left') || 0),
        };
    }
};

// =============================================
// MOBILE MENU HANDLER
// =============================================
const MobileMenuHandler = {
    init: function() {
        // Listen for shop mobile menu toggle
        const shopMobileToggle = document.getElementById('shopMobileToggle');
        if (shopMobileToggle) {
            shopMobileToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleShopMenu();
            });
        }

        // Handle orientation change
        window.addEventListener('orientationchange', () => {
            this.handleOrientationChange();
        });

        // Handle back button for mobile
        window.addEventListener('popstate', () => {
            this.closeMobileMenus();
        });
    },

    toggleShopMenu: function() {
        const menu = document.getElementById('shopMobileMenu');
        const toggle = document.getElementById('shopMobileToggle');
        const menuIcon = toggle?.querySelector('.icon-menu');
        const closeIcon = toggle?.querySelector('.icon-close');

        if (menu) {
            menu.classList.toggle('open');
            if (menuIcon && closeIcon) {
                menuIcon.style.display = menu.classList.contains('open') ? 'none' : 'block';
                closeIcon.style.display = menu.classList.contains('open') ? 'block' : 'none';
            }
        }
    },

    closeMobileMenus: function() {
        // Close sidebar
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) overlay.classList.remove('active');
        }

        // Close shop menu
        const shopMenu = document.getElementById('shopMobileMenu');
        if (shopMenu && shopMenu.classList.contains('open')) {
            shopMenu.classList.remove('open');
            const toggle = document.getElementById('shopMobileToggle');
            if (toggle) {
                toggle.querySelector('.icon-menu').style.display = 'block';
                toggle.querySelector('.icon-close').style.display = 'none';
            }
        }

        // Close all dropdowns
        document.querySelectorAll('.dropdown-menu.active').forEach(m => {
            m.classList.remove('active');
        });
    },

    handleOrientationChange: function() {
        // Close menus on orientation change
        this.closeMobileMenus();
        
        // Trigger resize event
        window.dispatchEvent(new Event('resize'));
    }
};

// =============================================
// FORM INPUT HANDLING
// =============================================
const MobileFormHandler = {
    init: function() {
        // Prevent iOS keyboard from hiding form content
        document.querySelectorAll('input, textarea, select').forEach(input => {
            input.addEventListener('focus', function() {
                // Scroll to element with delay to ensure keyboard is shown
                setTimeout(() => {
                    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            });
        });

        // Fix for input placeholders on iOS
        document.querySelectorAll('input[type="text"], input[type="email"], input[type="search"]').forEach(input => {
            input.addEventListener('blur', function() {
                // Reset placeholder visibility
                this.placeholder = this.getAttribute('placeholder') || '';
            });
        });
    }
};

// =============================================
// SCROLL HELPER
// =============================================
const ScrollHelper = {
    // Get current scroll position
    getScrollY: () => window.scrollY || document.documentElement.scrollTop,
    
    // Lock body scroll (for modals)
    lockScroll: function() {
        document.body.style.overflow = 'hidden';
        // Store scroll position
        this._scrollY = this.getScrollY();
    },

    // Unlock body scroll
    unlockScroll: function() {
        document.body.style.overflow = '';
        // Restore scroll position if needed
        if (this._scrollY !== undefined) {
            window.scrollTo(0, this._scrollY);
        }
    },

    // Smooth scroll to element
    smoothScrollTo: function(element, offset = 0) {
        const top = element.getBoundingClientRect().top + this.getScrollY() - offset;
        window.scrollTo({
            top: top,
            behavior: 'smooth'
        });
    },

    // Scroll to top
    scrollToTop: function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }
};

// =============================================
// TOUCH HANDLER
// =============================================
const TouchHandler = {
    // Detect swipe gestures
    init: function() {
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;

        document.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
        }, false);

        document.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            touchEndY = e.changedTouches[0].screenY;
            this.handleSwipe(touchStartX, touchStartY, touchEndX, touchEndY);
        }, false);
    },

    handleSwipe: function(startX, startY, endX, endY) {
        const diffX = startX - endX;
        const diffY = startY - endY;

        // Only handle horizontal swipes (threshold 50px)
        if (Math.abs(diffX) > 50 && Math.abs(diffY) < 50) {
            if (diffX > 0) {
                // Swiped left
                this.onSwipeLeft();
            } else {
                // Swiped right
                this.onSwipeRight();
            }
        }
    },

    onSwipeLeft: function() {
        // Can be customized - e.g., close sidebar
    },

    onSwipeRight: function() {
        // Can be customized - e.g., open sidebar
    }
};

// =============================================
// PERFORMANCE HELPER
// =============================================
const PerformanceHelper = {
    // Check if page is being viewed
    isPageVisible: () => !document.hidden,

    // Pause animations when page is hidden (save battery)
    pauseAnimationsOnHidden: function() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                document.body.style.animationPlayState = 'paused';
            } else {
                document.body.style.animationPlayState = 'running';
            }
        });
    },

    // Debounce window resize
    onResize: function(callback, delay = 250) {
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(callback, delay);
        });
    }
};

// =============================================
// NOTIFICATION HANDLER (PWA-style)
// =============================================
const NotificationHelper = {
    // Check if browser supports notifications
    isSupported: () => 'Notification' in window,

    // Request permission
    requestPermission: async function() {
        if (!this.isSupported()) return false;
        
        if (Notification.permission === 'granted') {
            return true;
        }
        
        if (Notification.permission !== 'denied') {
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        }
        
        return false;
    },

    // Show notification
    show: function(title, options = {}) {
        if (!this.isSupported() || Notification.permission !== 'granted') return;
        
        new Notification(title, {
            icon: '/assets/img/tokoibtidaiyah.png',
            ...options
        });
    }
};

// =============================================
// SAFE AREA SUPPORT
// =============================================
const SafeAreaHelper = {
    init: function() {
        if (this.isSupportedBrowser()) {
            this.applySafeArea();
            window.addEventListener('orientationchange', () => {
                setTimeout(() => this.applySafeArea(), 100);
            });
        }
    },

    isSupportedBrowser: () => CSS.supports('padding-top: max(env(safe-area-inset-top), 0px)'),

    applySafeArea: function() {
        const elements = document.querySelectorAll('[data-safe-area="true"]');
        elements.forEach(el => {
            const position = el.getAttribute('data-safe-area-position') || 'top';
            
            if (position === 'top') {
                el.style.paddingTop = 'max(var(--safe-area-inset-top, 0px), 16px)';
            } else if (position === 'bottom') {
                el.style.paddingBottom = 'max(var(--safe-area-inset-bottom, 0px), 16px)';
            }
        });
    }
};

// =============================================
// INITIALIZATION
// =============================================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize all mobile helpers
    if (ViewportHelper.isMobile() || ViewportHelper.isTablet()) {
        MobileMenuHandler.init();
        MobileFormHandler.init();
        TouchHandler.init();
        SafeAreaHelper.init();
        PerformanceHelper.pauseAnimationsOnHidden();
    }

    // Always setup performance helper
    PerformanceHelper.onResize(() => {
        // Custom resize handler
    });
});

// =============================================
// EXPORT FOR USE IN OTHER SCRIPTS
// =============================================
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        ViewportHelper,
        MobileMenuHandler,
        MobileFormHandler,
        ScrollHelper,
        TouchHandler,
        PerformanceHelper,
        NotificationHelper,
        SafeAreaHelper
    };
}
