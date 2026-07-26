<?php

namespace App\Livewire\History;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class VersionHistoryPage extends Component
{
    public function render()
    {
        return view('livewire.history.version-history-page', ['title'=>'\u0633\u062C\u0644 \u0627\u0644\u0625\u0635\u062F\u0627\u0631\u0627\u062A']);
    }
}
