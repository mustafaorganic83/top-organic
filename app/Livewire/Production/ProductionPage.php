<?php

namespace App\Livewire\Production;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductionPage extends Component
{
    public function render()
    {
        return view('livewire.production.production-page', ['title'=>'\u0627\u0644\u0625\u0646\u062A\u0627\u062C']);
    }
}
