<?php

namespace App\Livewire\Snapshots;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SnapshotsPage extends Component
{
    public function render()
    {
        return view('livewire.snapshots.snapshots-page', ['title'=>'\u0627\u0644\u0644\u0642\u0637\u0627\u062A']);
    }
}
