<?php

namespace CipiGui\Livewire;

use Livewire\Component;

class JobMonitor extends Component
{
    public ?string $jobId = null;

    public ?string $status = null;

    public ?string $output = null;

    public ?array $result = null;

    public bool $running = false;

    public string $label = '';

    public function mount(
        ?string $jobId = null,
        ?string $status = null,
        bool $running = false,
        string $label = '',
    ): void {
        $this->jobId = $jobId;
        $this->status = $status;
        $this->running = $running;
        $this->label = $label;
    }

    public function render()
    {
        return view('cipi-gui::livewire.job-monitor');
    }
}
