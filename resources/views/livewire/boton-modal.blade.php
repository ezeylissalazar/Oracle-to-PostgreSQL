<!-- boton-modal.blade.php -->
<div>
    <!-- Emite el evento 'openModal' al hacer clic -->
    <button wire:click="isModalOpen('{{ $table }}')"
        class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-blue-600 border border-transparent rounded-lg active:bg-blue-600 hover:bg-blue-700 focus:outline-none focus:shadow-outline-blue">
        Migrar Estructura Personalizada
    </button>

</div>
