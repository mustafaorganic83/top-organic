<?php

namespace App\Livewire\Prepared;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PreparedItemPage extends Component
{
    public function render()
    {
        return view('livewire.prepared.prepared-item-page', ['title'=>'\u0627\u0644\u0645\u062D\u0636\u0651\u0631\u0627\u062A']);
    }
}
