<div>
    <div x-data="{ isModalDataOpen: @entangle('isOpenData') }">
        <!-- Modal -->
        <div x-show="isModalDataOpen" x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-30 flex items-end bg-black bg-opacity-50 sm:items-center sm:justify-center">

            <!-- Modal Content -->
            <div x-show="isModalDataOpen" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 transform translate-y-1/2" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0 transform translate-y-1/2" @click.away="isModalDataOpen = false"
                @keydown.escape="isModalDataOpen = false"
                class="w-full px-6 py-4 bg-white rounded-t-lg sm:rounded-lg sm:m-4 sm:max-w-xl" role="dialog">

                <!-- Header -->
                <header class="flex justify-between items-center border-b pb-2">
                    <h2 class="text-lg font-semibold text-gray-700">Migrar Datos</h2>
                    <button class="text-gray-400 hover:text-gray-700" @click="isModalDataOpen = false">
                        <x-icon name="x-circle" />
                    </button>
                </header>

                <!-- Modal Body -->
                <form wire:submit.prevent="saveChangesData">
                    @error('oracleTableNameData')
                        <span class="text-red-600">{{ $message }}</span>
                    @enderror

                    @error('postgresTableNameData')
                        <span class="text-red-600">{{ $message }}</span>
                    @enderror
                    <div class="mt-4 space-y-4">
                        <!-- Nombre de la tabla en Oracle -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tabla en Oracle (Origen)</label>
                            <input type="text" wire:model="oracleTableName"
                                class="w-full p-2 border rounded-md bg-gray-100" readonly />
                        </div>

                        <!-- Nombre de la tabla en PostgreSQL -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tabla en PostgreSQL (Destino)</label>
                            <input type="text" wire:model="postgresTableName" class="w-full p-2 border rounded-md"
                                placeholder="Ingrese el nombre de la tabla en PostgreSQL" />

                            @error('postgresTableName')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <!-- Footer -->
                    <div class="mt-4 flex justify-end">
                        <button @click="isModalDataOpen = false" type="button"
                            class="px-5 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-200">
                            Cancelar
                        </button>
                        <button type="submit"
                            class="ml-2 px-5 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700">
                            Aceptar
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <div x-data="{ isModalDataOpen: @entangle('isOpenDataSelect') }">
        <!-- Modal -->
        <div x-show="isModalDataOpen" x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-30 flex items-end bg-black bg-opacity-50 sm:items-center sm:justify-center">

            <!-- Modal Content -->
            <div x-show="isModalDataOpen" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 transform translate-y-1/2" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0 transform translate-y-1/2" @click.away="isModalDataOpen = false"
                @keydown.escape="isModalDataOpen = false"
                class="w-full px-6 py-4 bg-white rounded-t-lg sm:rounded-lg sm:m-4 sm:max-w-xl" role="dialog">

                <!-- Header -->
                <header class="flex justify-between items-center border-b pb-2">
                    <h2 class="text-lg font-semibold text-gray-700">Seleccionar Campos</h2>
                    <button class="text-gray-400 hover:text-gray-700" @click="isModalDataOpen = false">
                        <x-icon name="x-circle" />
                    </button>
                </header>

                <!-- Modal Body -->
                <div class="overflow-y-auto px-4" style="max-height: calc(100vh - 200px);">
                    <form wire:submit.prevent="saveChangesDataProcess">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 1rem;">
                            <!-- Columna Izquierda (Oracle) -->
                            <div style="display: grid; grid-template-columns: 150px 1fr; gap: 8px;">
                                <h3 class="text-md font-semibold text-gray-700 mb-2" style="grid-column: span 2;">
                                    Columnas en Oracle</h3>
                                @foreach ($oracleColumns as $index => $column)
                                    <div style="display: contents;">
                                        <label class="block text-sm font-medium text-gray-700"
                                            style="align-content: center; grid-column: 1;">
                                            {{ $column }}
                                        </label>
                                        <input type="hidden" wire:model="oracleColumns.{{ $index }}"
                                            style="grid-column: 2;">
                                    </div>
                                @endforeach
                            </div>

                            <!-- Columna Derecha (PostgreSQL) -->
                            <div style="display: grid; grid-template-columns: 150px 1fr; gap: 8px;">
                                <h3 class="text-md font-semibold text-gray-700 mb-2" style="grid-column: span 2;">
                                    Seleccionar destino en PostgreSQL </h3>
                                <div style="  width: 230px !important;">
                                    @foreach ($oracleColumns as $index => $column)
                                        <select wire:model="mappedColumns.{{ $column }}"
                                            wire:change="updateSelection" class="w-full p-2 mb-2 border rounded-md"
                                            style="">
                                            <option value="">Seleccione destino</option>
                                            @foreach ($postgresColumns as $pgColumn)
                                                <option value="{{ $pgColumn }}"
                                                    {{ in_array($pgColumn, $selectedPgColumns) && ($mappedColumns[$column] ?? '') !== $pgColumn ? 'disabled' : '' }}>
                                                    {{ $pgColumn }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endforeach
                                </div>
                            </div>
                        </div>


                        <div class="mt-4">
                            @if (isset($postgresForeignKeys) && !empty($postgresForeignKeys))
                                <h3 class="text-md font-semibold text-gray-700 mb-2">
                                    Valores por defecto para claves foráneas
                                </h3>
                            @endif
                        
                            @foreach ($postgresForeignKeys as $foreignKey)
                                @if (!in_array($foreignKey, $hiddenForeignKeys))
                                    <div class="mb-2">
                                        <label class="block text-sm font-medium text-gray-700">{{ $foreignKey }}</label>
                                        <input type="text" wire:model="foreignKeyValues.{{ $foreignKey }}"
                                            class="w-full p-2 border rounded-md" placeholder="Ingrese valor por defecto">
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        

                        <!-- Footer -->
                        <div class="mt-4 flex justify-end">
                            <button @click="isModalDataOpen = false" type="button"
                                class="px-5 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-200">
                                Cancelar
                            </button>
                            <button type="submit"
                                class="ml-2 px-5 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700">
                                Aceptar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
