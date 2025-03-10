<?php

namespace App\Livewire;

use Livewire\Component;

class BotonData extends Component
{
    public $table;
    
    public function isModalDataOpen($name)
    {
        $this->dispatch('name-table-data', nameTableData: $name);
    }
    public function render()
    {
        return view('livewire.boton-data');
    }
}
