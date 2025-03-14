<!DOCTYPE html>
<html x-data="data()" lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BIIP MIDDLE</title>
    <link href="{{ asset('assets/css/fonts.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/css/tailwind.output.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/chart.min.css') }}" />
    <link rel="icon" type="image/x-icon" href="{{ asset('assets/img/icon.png') }}">
    @livewireStyles
</head>

<body>
    <div class="flex h-screen bg-gray-50 dark:bg-gray-900" :class="{ 'overflow-hidden': isSideMenuOpen }">
        <div class="flex flex-col flex-1 w-full">

            <main class="h-full overflow-y-auto">
                <div class="container px-6 mx-auto grid">
                    <h2 class="my-6 text-2xl font-semibold text-gray-700 dark:text-gray-200">
                        Tablas
                    </h2>
                    <div class="w-full overflow-hidden rounded-lg shadow-xs">
                        <div class="w-full overflow-x-auto">
                            @if (session('error'))
                                <div id="error-alert"
                                    class="max-w-md mx-auto bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-lg shadow-md flex items-start justify-between">
                                    <div class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path
                                                d="M12 8v4m0 4v.01M5 12l1.8 1.8M5 12l1.8-1.8m12 0l-1.8 1.8m1.8-1.8L19 12" />
                                        </svg>
                                        <strong class="font-semibold">{{ session('error') }}</strong>
                                    </div>
                                    <button onclick="document.getElementById('error-alert').style.display = 'none';"
                                        class="text-red-500 hover:text-red-700 focus:outline-none">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            @elseif (session('success'))
                                <div id="success-alert"
                                    class="max-w-md mx-auto bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-lg shadow-md flex items-start justify-between">
                                    <div class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 12l2 2l4 -4m-2 2h6M5 12l2 2l4 -4m0 2h6" />
                                        </svg>
                                        <strong class="font-semibold">{{ session('success') }}</strong>
                                    </div>
                                    <button onclick="document.getElementById('success-alert').style.display = 'none';"
                                        class="text-green-500 hover:text-green-700 focus:outline-none">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            @endif

                            <div class="mb-4 flex items-center justify-start">
                                <form method="GET" action="{{ route('tables.index') }}"
                                    class="flex items-center space-x-2 mr-2">
                                    <input type="text" name="search" value="{{ request('search') }}"
                                        class="px-3 py-2 rounded-md border text-sm dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-purple-600"
                                        placeholder="Buscar tabla..." />
                                    <button type="submit"
                                        class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 rounded-lg hover:bg-purple-700 focus:outline-none focus:shadow-outline-purple">
                                        Buscar
                                    </button>
                                </form>
                                <a href="{{ route('migration.index') }}"
                                    class="flex items-center space-x-2 px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-red-600 border border-transparent rounded-lg hover:bg-red-700 focus:outline-none focus:shadow-outline-red">
                                    Borrar
                                </a>
                            </div>
                            <table class="w-full whitespace-no-wrap">
                                <thead>
                                    <tr
                                        class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b dark:border-gray-700 bg-gray-50 dark:text-gray-400 dark:bg-gray-800">
                                        <th class="px-4 py-3">Esquema</th>
                                        <th class="px-4 py-3">Tabla Oracle</th>
                                        <th class="px-4 py-3">Campos Oracle</th>
                                        <th class="px-4 py-3">Tabla PostgreSQL</th>
                                        <th class="px-4 py-3">Campos PostgreSQL</th>
                                        <th class="px-4 py-3">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y dark:divide-gray-700 dark:bg-gray-800">
                                    @foreach ($oracleTables as $table)
                                        @php
                                            $tableNameLower = strtolower($table->table_name); // Convertimos el nombre de Oracle a minúsculas
                                            $isMigrated = [];
                                        @endphp
                                        <tr class="text-gray-700 dark:text-gray-400">
                                            <td class="px-4 py-3 text-sm">{{ $table->schema_name }}</td>
                                            <td class="px-4 py-3 text-sm">{{ $table->table_name }}</td>
                                            <td class="px-4 py-3 text-sm">
                                                @if (isset($columns[$table->table_name]))
                                                    <ul>
                                                        @foreach ($columns[$table->table_name] as $column)
                                                            <li>{{ $column->column_name }}</li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <span class="text-gray-500">No disponible</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                @if (isset($pgsqlTables[$tableNameLower]))
                                                    {{ $pgsqlTables[$tableNameLower] }} {{-- Nombre real en PostgreSQL --}}
                                                @else
                                                    <span class="text-gray-500">Sin migración</span>
                                                @endif
                                            </td>
                                            
                                            <td class="px-4 py-3 text-sm">
                                                @if (isset($pgsqlColumns[$tableNameLower]) && count($pgsqlColumns[$tableNameLower]) > 0)
                                                    <ul>
                                                        @foreach ($pgsqlColumns[$tableNameLower] as $column)
                                                            <li>{{ $column->column_name }}</li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <span class="text-gray-500">Sin migración</span>
                                                @endif
                                            </td>
                                            
                                            <td class="px-4 py-3 text-sm">
                                                <div class="flex flex-col items-center justify-center w-full space-y-2">
                                                    {{-- Botón para migrar estructura (Funcion Generica) --}}
                                                    {{-- <form
                                                        action="{{ route('migration.migrateStructure', ['table' => $table->table_name]) }}"
                                                        method="POST" class="w-full">
                                                        @csrf
                                                        <button
                                                            class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-blue-600 border border-transparent rounded-lg active:bg-blue-600 hover:bg-blue-700 focus:outline-none focus:shadow-outline-blue">
                                                            Migrar Estructura Generica
                                                        </button>
                                                    </form> --}}
                                                    {{-- Botón para migrar estructura (Funcion Separada)  --}}
                                                    <livewire:boton-modal table="{{ $table->table_name }}" />
                                                    <livewire:boton-data table="{{ $table->table_name }}" />

                                                    @if ($isMigrated)
                                                        <span
                                                            class="text-green-500 font-semibold text-center">Migrada</span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <livewire:modal-structure />
                            <livewire:modal-data />


                        </div>
                        {{-- Paginación personalizada --}}
                        <div
                            class="grid px-4 py-3 text-xs font-semibold tracking-wide text-gray-500 uppercase border-t dark:border-gray-700 bg-gray-50 sm:grid-cols-9 dark:text-gray-400 dark:bg-gray-800">
                            <span class="flex items-center col-span-3">
                                {{-- Mostrar información de la paginación --}}
                                Showing {{ $oracleTables->firstItem() }}-{{ $oracleTables->lastItem() }} of
                                {{ $oracleTables->total() }}
                            </span>
                            <span class="col-span-2"></span>

                            <!-- Paginación -->
                            <span class="flex col-span-4 mt-2 sm:mt-auto sm:justify-end">
                                <nav aria-label="Table navigation">
                                    <ul class="inline-flex items-center">
                                        {{-- Botón de "Previous" --}}
                                        @if ($oracleTables->onFirstPage())
                                            <li>
                                                <button class="px-3 py-1 rounded-md rounded-l-lg focus:outline-none"
                                                    aria-label="Previous" disabled>
                                                    <svg aria-hidden="true" class="w-4 h-4 fill-current"
                                                        viewBox="0 0 20 20">
                                                        <path
                                                            d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                                            clip-rule="evenodd" fill-rule="evenodd"></path>
                                                    </svg>
                                                </button>
                                            </li>
                                        @else
                                            <li>
                                                <a href="{{ $oracleTables->previousPageUrl() }}"
                                                    class="px-3 py-1 rounded-md rounded-l-lg focus:outline-none"
                                                    aria-label="Previous">
                                                    <svg aria-hidden="true" class="w-4 h-4 fill-current"
                                                        viewBox="0 0 20 20">
                                                        <path
                                                            d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                                            clip-rule="evenodd" fill-rule="evenodd"></path>
                                                    </svg>
                                                </a>
                                            </li>
                                        @endif

                                        {{-- Páginas numéricas --}}
                                        @php
                                            $currentPage = $oracleTables->currentPage();
                                            $totalPages = $oracleTables->lastPage();
                                            $pageRange = 2; // Número de páginas que se mostrarán antes y después de la página actual
                                        @endphp

                                        @for ($i = 1; $i <= $totalPages; $i++)
                                            @if ($i <= $currentPage + $pageRange && $i >= $currentPage - $pageRange)
                                                <li>
                                                    <a href="{{ $oracleTables->url($i) }}"
                                                        class="px-3 py-1 rounded-md {{ $oracleTables->currentPage() == $i ? 'bg-purple-600 text-white' : 'text-gray-700' }} focus:outline-none">
                                                        {{ $i }}
                                                    </a>
                                                </li>
                                            @elseif ($i == 1 || $i == $totalPages || ($i >= $currentPage - $pageRange && $i <= $currentPage + $pageRange))
                                                {{-- Aquí puedes mostrar los puntos suspensivos si el número de página no está dentro del rango visible --}}
                                                @if ($i == 1 || $i == $totalPages)
                                                    <li>
                                                        <a href="{{ $oracleTables->url($i) }}"
                                                            class="px-3 py-1 rounded-md {{ $oracleTables->currentPage() == $i ? 'bg-purple-600 text-white' : 'text-gray-700' }} focus:outline-none">
                                                            {{ $i }}
                                                        </a>
                                                    </li>
                                                @endif
                                            @endif
                                        @endfor

                                        {{-- Botón de "Next" --}}
                                        @if ($oracleTables->hasMorePages())
                                            <li>
                                                <a href="{{ $oracleTables->nextPageUrl() }}"
                                                    class="px-3 py-1 rounded-md rounded-r-lg focus:outline-none"
                                                    aria-label="Next">
                                                    <svg class="w-4 h-4 fill-current" aria-hidden="true"
                                                        viewBox="0 0 20 20">
                                                        <path
                                                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                            clip-rule="evenodd" fill-rule="evenodd"></path>
                                                    </svg>
                                                </a>
                                            </li>
                                        @else
                                            <li>
                                                <button class="px-3 py-1 rounded-md rounded-r-lg focus:outline-none"
                                                    aria-label="Next" disabled>
                                                    <svg class="w-4 h-4 fill-current" aria-hidden="true"
                                                        viewBox="0 0 20 20">
                                                        <path
                                                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                            clip-rule="evenodd" fill-rule="evenodd"></path>
                                                    </svg>
                                                </button>
                                            </li>
                                        @endif
                                    </ul>
                                </nav>
                            </span>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <!-- Modal body -->
    <!-- Modal -->
    @livewireScripts

    <script>
        Livewire;
    </script>

</body>

</html>
