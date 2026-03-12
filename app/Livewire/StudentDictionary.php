<?php

namespace App\Livewire;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Livewire\Component;
use Livewire\WithPagination;

class StudentDictionary extends Component
{
    use WithPagination;

    public $search = '';
    public $dictionary_id = 'all';

    // Обновляем пагинацию при поиске
    public function updatingSearch()
    {
        $this->resetPage();
    }
    
    public function updatingDictionaryId()
    {
        $this->resetPage();
    }

    public function render()
    {
        $dictionaries = Dictionary::where('is_active', true)->get();

        $words = DictionaryWord::query();

        if ($this->dictionary_id !== 'all') {
            $words->where('dictionary_id', $this->dictionary_id);
        }

        if (strlen($this->search) >= 2) {
            $words->where(function ($query) {
                $query->where('devanagari', 'like', '%' . $this->search . '%')
                      ->orWhere('iast', 'like', '%' . $this->search . '%')
                      ->orWhere('cyrillic', 'like', '%' . $this->search . '%')
                      ->orWhere('translation', 'like', '%' . $this->search . '%');
            });
        }

        return view('livewire.student-dictionary', [
            'dictionaries' => $dictionaries,
            'words' => $words->paginate(20),
        ]);
    }
}