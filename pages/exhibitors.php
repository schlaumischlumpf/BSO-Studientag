<?php
// Fachbereiche laden
?>

<!-- Fachbereiche-Übersicht -->
<div class="space-y-6">

    <!-- Suchleiste und Aktionen -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="relative max-w-md w-full">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Fachbereich suchen..." 
                   class="w-full pl-10 pr-4 py-2.5 bg-white border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition"
                   onkeyup="filterExhibitors()">
        </div>
        
        <div class="flex items-center gap-2">
            <button id="sortButton" onclick="sortExhibitors('name')" class="flex items-center px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition">
                <i class="fas fa-sort-alpha-down mr-2"></i> A-Z
            </button>
            <?php if (isAdmin()): ?>
            <a href="?page=admin-exhibitors&action=add" class="flex items-center px-4 py-2 bg-emerald-500 text-white rounded-lg text-sm font-medium hover:bg-emerald-600 transition shadow-sm">
                <i class="fas fa-plus mr-2"></i> Hinzufügen
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Karten-Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5" id="exhibitorGrid">
        <?php foreach ($exhibitors as $index => $exhibitor):
            $colors = ['mint', 'lavender', 'peach', 'sky'];
            $colorClass = $colors[$index % count($colors)];
        ?>
        <div class="exhibitor-card bg-white rounded-xl border border-gray-100 p-5 hover:border-gray-200"
             data-name="<?php echo strtolower(htmlspecialchars($exhibitor['name'])); ?>">
            
            <!-- Card Header -->
            <div class="flex items-start space-x-4 mb-4">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm" style="background: linear-gradient(135deg, var(--color-pastel-<?php echo $colorClass; ?>-light, #f3f4f6) 0%, white 100%); border: 1px solid rgba(0,0,0,0.05);">
                    <i class="fas fa-university text-gray-400 text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 text-base leading-tight mb-1">
                        <?php echo htmlspecialchars(html_entity_decode($exhibitor['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
                    </h3>
                    <?php if (!empty($exhibitor['short_description'])): ?>
                    <p class="text-sm text-gray-500 line-clamp-2">
                        <?php echo htmlspecialchars(html_entity_decode($exhibitor['short_description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex items-center gap-2 pt-4 border-t border-gray-100">
                <?php if (!isTeacher() && !isAdmin()): ?>
                <a href="?page=registration&exhibitor=<?php echo $exhibitor['id']; ?>" 
                   class="flex-1 flex items-center justify-center px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 hover:shadow-md" style="background: linear-gradient(135deg, var(--color-pastel-mint) 0%, #6bc4a6 100%); color: #1f2937;">
                    <i class="fas fa-user-plus mr-2"></i> Einschreiben
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($exhibitors)): ?>
    <div class="text-center py-16">
        <i class="fas fa-university text-6xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-lg">Keine Fachbereiche gefunden</p>
    </div>
    <?php endif; ?>
</div>

<style>
    /* Exhibitor Card Hover Animation */
    .exhibitor-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        animation: fadeInUp 0.4s ease-out forwards;
        opacity: 0;
    }
    
    .exhibitor-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.12);
        border-color: #a8e6cf;
    }
    
    .exhibitor-card:nth-child(1) { animation-delay: 0.05s; }
    .exhibitor-card:nth-child(2) { animation-delay: 0.1s; }
    .exhibitor-card:nth-child(3) { animation-delay: 0.15s; }
    .exhibitor-card:nth-child(4) { animation-delay: 0.2s; }
    .exhibitor-card:nth-child(5) { animation-delay: 0.25s; }
    .exhibitor-card:nth-child(6) { animation-delay: 0.3s; }
    
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<script>
let sortDirection = 'asc';

function filterExhibitors() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.exhibitor-card').forEach(card => {
        const name = card.getAttribute('data-name');
        card.style.display = name.includes(searchTerm) ? 'block' : 'none';
    });
}

function sortExhibitors(by) {
    const grid = document.getElementById('exhibitorGrid');
    const cards = Array.from(grid.querySelectorAll('.exhibitor-card'));

    sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';

    cards.sort((a, b) => {
        const nameA = a.getAttribute('data-name');
        const nameB = b.getAttribute('data-name');
        return sortDirection === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    });

    const sortBtn = document.getElementById('sortButton');
    sortBtn.innerHTML = sortDirection === 'asc'
        ? '<i class="fas fa-sort-alpha-down mr-2"></i> A-Z'
        : '<i class="fas fa-sort-alpha-up mr-2"></i> Z-A';

    cards.forEach(card => grid.appendChild(card));
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', filterExhibitors);
        searchInput.addEventListener('keyup', filterExhibitors);
    }
});
</script>
