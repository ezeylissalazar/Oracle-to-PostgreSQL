<?php

namespace App\Livewire;

use Livewire\Component;

class BotonModal extends Component
{
    public $table;
    
    public function isModalOpen($name)
    {
        $this->dispatch('name-table', nameTable: $name);
    }
    public function render()
    {
        return view('livewire.boton-modal');
    }
}
