// scripts/sidebar-state.js

/**
 * Gestiona el estado de la barra lateral (contraída/expandida)
 * y su persistencia usando localStorage.
 */
class SidebarStateManager {
    constructor() {
        this.storageKey = 'sidebarState';
        this.currentPage = this.getCurrentPage();
        this.init();
    }

    /**
     * Inicializa la clase: aplica el estado guardado,
     * configura listeners y marca la página activa.
     */
    init() {
        // 1. Deshabilitar transiciones temporalmente para evitar el "flash" (Flickering)
        this.disableTransitions(); 
        
        // 2. Aplicar estado guardado al cargar la página
        this.applySavedState();
        
        // 3. Configurar listeners
        this.setupEventListeners();
        
        // 4. Marcar página activa
        this.setActivePage();

        // 5. Barra superior y menú deslizable para móvil/tablet
        this.setupMobileNav();

        // 6. Reactivar las transiciones
        this.enableTransitions();
    }

    // --- Métodos de Estado y Almacenamiento ---

    getCurrentPage() {
        const path = window.location.pathname;
        const page = path.split('/').pop() || 'index.html';
        return page.toLowerCase();
    }

    getState() {
        const savedState = localStorage.getItem(this.storageKey);
        return savedState === 'contraida';
    }

    saveState(isContraida) {
        localStorage.setItem(this.storageKey, isContraida ? 'contraida' : 'expandida');
    }

    // --- Métodos de Transición/Estilo para evitar el Flickering ---

    disableTransitions() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');

        document.body.style.setProperty('transition', 'none', 'important');
        if (sidebar) sidebar.style.setProperty('transition', 'none', 'important');
        if (mainContent) mainContent.style.setProperty('transition', 'none', 'important');
    }

    enableTransitions() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');

        setTimeout(() => {
            document.body.style.removeProperty('transition');
            if (sidebar) sidebar.style.removeProperty('transition');
            if (mainContent) mainContent.style.removeProperty('transition');
        }, 50);
    }

    // --- Métodos de Interacción DOM ---

    applySavedState() {
        const isContraida = this.getState();
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebar) {
            if (isContraida) {
                sidebar.classList.add('contraida');
                if (mainContent) mainContent.classList.add('contraido');
            } else {
                sidebar.classList.remove('contraida');
                if (mainContent) mainContent.classList.remove('contraido');
            }
            this.updateToggleIcon(isContraida);
        }
    }

    setupEventListeners() {
        const toggleButton = document.getElementById('toggleSidebar'); 
        
        if (toggleButton) {
            toggleButton.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }

        this.setupNavigationLinks();
    }

    toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');
        const isContraida = sidebar.classList.contains('contraida');

        if (isContraida) {
            // Expandir
            sidebar.classList.remove('contraida');
            if (mainContent) mainContent.classList.remove('contraido');
            this.saveState(false);
        } else {
            // Contraer
            sidebar.classList.add('contraida');
            if (mainContent) mainContent.classList.add('contraido');
            this.saveState(true);
        }

        this.updateToggleIcon(!isContraida);
    }

    updateToggleIcon(isContraida) {
        const toggleButton = document.getElementById('toggleSidebar');
        if (toggleButton) {
            const icon = toggleButton.querySelector('i');
            if (icon) {
                icon.className = isContraida ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            }
        }
    }

    setActivePage() {
        const links = document.querySelectorAll('.sidebar a');
        links.forEach(link => {
            const href = link.getAttribute('href');
            if (href) {
                const linkPage = href.split('/').pop().toLowerCase();
                
                const isCurrentPage = (
                    linkPage === this.currentPage || 
                    (this.currentPage === 'index.html' && href === '../index.html') ||
                    (this.currentPage.includes('incidencia') && linkPage.includes('incidencia'))
                );

                if (isCurrentPage) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            }
        });
    }

    // --- Navegación móvil (≤1024px, ver "NAVEGACIÓN MÓVIL" en styles.css) ---

    /**
     * Sustituye el botón "Volver al Inicio" por una barra fija con
     * menú, título de la página e inicio. El menú abre la barra lateral
     * como panel deslizable sobre un fondo oscuro.
     */
    setupMobileNav() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar || document.querySelector('.barra-movil')) return;

        const linkInicio = sidebar.querySelector('a[href$="index.html"]');
        const hrefInicio = linkInicio ? linkInicio.getAttribute('href') : '../index.html';
        const linkActivo = sidebar.querySelector('a.active .sidebar-link-text');
        const titulo = (document.title || (linkActivo && linkActivo.textContent) || 'SIPCONS').trim();

        if (!sidebar.id) sidebar.id = 'sidebar-principal';

        const barra = document.createElement('header');
        barra.className = 'barra-movil';
        barra.innerHTML = `
            <button type="button" class="barra-movil-btn" aria-label="Abrir menú"
                    aria-controls="${sidebar.id}" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="barra-movil-titulo"></h1>
            <a class="barra-movil-btn" href="${hrefInicio}" aria-label="Inicio" title="Inicio">
                <i class="fas fa-home"></i>
            </a>`;
        barra.querySelector('.barra-movil-titulo').textContent = titulo;

        const fondo = document.createElement('div');
        fondo.className = 'sidebar-fondo';

        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'barra-movil-btn sidebar-cerrar';
        cerrar.setAttribute('aria-label', 'Cerrar menú');
        cerrar.innerHTML = '<i class="fas fa-times"></i>';
        const header = sidebar.querySelector('.sidebar-header');
        if (header) header.appendChild(cerrar);

        const botonVolver = document.querySelector('.mobile-home-btn');
        if (botonVolver) botonVolver.remove();
        document.body.prepend(barra);
        document.body.appendChild(fondo);
        document.body.classList.add('con-barra-movil');

        const botonMenu = barra.querySelector('button');

        const abrir = () => {
            sidebar.classList.add('abierta');
            fondo.classList.add('visible');
            document.body.classList.add('menu-abierto');
            botonMenu.setAttribute('aria-expanded', 'true');
            cerrar.focus();
        };
        const cerrarMenu = () => {
            if (!sidebar.classList.contains('abierta')) return;
            sidebar.classList.remove('abierta');
            fondo.classList.remove('visible');
            document.body.classList.remove('menu-abierto');
            botonMenu.setAttribute('aria-expanded', 'false');
        };

        botonMenu.addEventListener('click', abrir);
        cerrar.addEventListener('click', () => { cerrarMenu(); botonMenu.focus(); });
        fondo.addEventListener('click', cerrarMenu);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') cerrarMenu();
        });
        // Al pasar a escritorio el panel deja de ser deslizable
        window.matchMedia('(min-width: 1025px)').addEventListener('change', (e) => {
            if (e.matches) cerrarMenu();
        });
    }

    setupNavigationLinks() {
        const navLinks = document.querySelectorAll('a[href]');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                const sidebar = document.querySelector('.sidebar');
                const isContraida = sidebar.classList.contains('contraida');
                this.saveState(isContraida);
            });
        });
    }
}

// Inicializar la clase cuando el DOM esté completamente cargado
document.addEventListener('DOMContentLoaded', () => {
    new SidebarStateManager();
});