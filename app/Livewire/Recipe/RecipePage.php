<?php

namespace App\Livewire\Recipe;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class RecipePage extends Component
{
    public function render()
    {
        return view('livewire.recipe.recipe-page', ['title'=>'\u0627\u0644\u0648\u0635\u0641\u0627\u062A']);
    }
}
