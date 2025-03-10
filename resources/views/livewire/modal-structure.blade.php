<div x-data="{ isModalOpen: @entangle('isOpen') }">
    <!-- Modal -->
    <div x-show="isModalOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-30 flex items-end bg-black bg-opacity-50 sm:items-center sm:justify-center">
        <!-- Modal Content -->
        <div x-show="isModalOpen" x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 transform translate-y-1/2" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0 transform translate-y-1/2" @click.away="isModalOpen = false"
            @keydown.escape="isModalOpen = false"
            class="w-full px-6 py-4 overflow-hidden bg-white rounded-t-lg sm:rounded-lg sm:m-4 sm:max-w-xl"
            role="dialog">
            <!-- Header -->
            <header class="flex justify-end">
                <button
                    class="inline-flex items-center justify-center w-6 h-6 text-gray-400 transition-colors duration-150 rounded dark:hover:text-gray-200 hover:text-gray-700"
                    aria-label="close" @click="isModalOpen = false">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" role="img" aria-hidden="true">
                        <path
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd" fill-rule="evenodd"></path>
                    </svg>
                </button>
            </header>

            <!-- Modal Body -->
            <div class="overflow-y-auto px-4" style="max-height: calc(100vh - 200px);">
                <div class="mt-4 mb-6">
                    <p class="mb-2 text-lg font-semibold text-gray-700">Migrar Estructura: {{ $tableNameOracle }}</p>

                    <form wire:submit.prevent="saveChanges">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700">Tabla</label>
                            <input type="text" wire:model="newTableName" class="w-full p-2 border rounded-md" placeholder="{{ $tableName }}" />
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Mostrar los campos de PostgreSQL y los inputs para nuevos nombres -->
                            @foreach ($newFields as $index => $field)
                                <div class="flex items-center mb-2">
                                    <label class="w-full text-left">{{ $field['original'] }}</label>
                                    <input type="text" wire:model="newFields.{{ $index }}.new"
                                        class="w-full p-2 border rounded-md" placeholder="Nuevo nombre" value="{{ $field['new'] }}" />
                                </div>
                            @endforeach
                        </div>
                        
                        <div class="mt-4 flex justify-end">
                            <button type="submit"
                                class="w-full px-5 py-3 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg sm:w-auto sm:px-4 sm:py-2 active:bg-purple-600 hover:bg-purple-700">
                                Aceptar
                            </button>
                        </div>
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
</div>
