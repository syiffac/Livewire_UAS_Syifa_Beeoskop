<?php

namespace App\Livewire\User;

use App\Models\JadwalTayang;
use App\Models\Kursi;
use App\Models\Tiket;
use App\Models\Transaksi;
use App\Models\DetailTransaksi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

class Checkout extends Component
{
    use WithFileUploads;
    
    #[Layout('livewire.components.layouts.app')]
    
    public $jadwalId;
    public $selectedSeats = [];
    public $jadwal;
    public $film;
    public $studio;
    public $ticketPrice;
    public $totalAmount = 0;
    
    public $showPaymentDetails = true; // Default ke true agar selalu menampilkan opsi upload
    public $paymentMethod = 'bank_transfer';
    public $paymentProof;
    public $bankAccounts = [
        'bca' => [
            'name' => 'BCA',
            'account_number' => '1234-5678-9012',
            'account_name' => 'PT. Beeos Cinema'
        ],
        'mandiri' => [
            'name' => 'Mandiri',
            'account_number' => '9876-5432-1098',
            'account_name' => 'PT. Beeos Cinema'
        ],
        'bni' => [
            'name' => 'BNI',
            'account_number' => '0123-4567-8901',
            'account_name' => 'PT. Beeos Cinema'
        ]
    ];
    public $selectedBank = 'bca';
    
    public $countdownMinutes = 10;
    public $countdownSeconds = 0;
    public $countdownExpired = false;
    public $bookingCode = '';
    
    public $transactionComplete = false;

    public $debugMode = false; // Tambahkan properti baru di kelas
    
    public function mount()
    {
        // Redirect if user is not authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }
        
        // Get selected seats and jadwal from session
        $this->selectedSeats = session('selected_seats', []);
        $this->jadwalId = session('jadwal_id');
        
        if (empty($this->selectedSeats) || !$this->jadwalId) {
            session()->flash('error', 'No seats selected. Please select seats first.');
            return redirect()->route('movies');
        }
        
        // Load jadwal and related data
        $this->jadwal = JadwalTayang::with(['film', 'studio'])->findOrFail($this->jadwalId);
        $this->film = $this->jadwal->film;
        $this->studio = $this->jadwal->studio;
        $this->ticketPrice = $this->jadwal->price;
        $this->totalAmount = count($this->selectedSeats) * $this->ticketPrice;
        
        // Generate random booking code
        $this->bookingCode = Transaksi::generateBookingCode();
        
        // Add time session for booking (10 minutes)
        session()->put('checkout_expires_at', now()->addMinutes($this->countdownMinutes));
        
        // Pastikan showPaymentDetails selalu true jika paymentMethod adalah bank_transfer
        if ($this->paymentMethod === 'bank_transfer') {
            $this->showPaymentDetails = true;
        }
    }
    
    public function updatedPaymentMethod()
    {
        if ($this->paymentMethod === 'bank_transfer') {
            $this->showPaymentDetails = true;
        } else {
            $this->showPaymentDetails = false;
        }
    }
    
    // Perbarui metode
    public function completeTransaction()
    {
        // Log semua input di awal untuk membantu debug
        $inputData = [
            'paymentProof' => $this->paymentProof ? 'exists' : 'missing',
            'selectedBank' => $this->selectedBank,
            'selectedSeats' => $this->selectedSeats,
        ];
        
        Log::channel('daily')->info('Complete Payment called with:', $inputData);
        
        try {
            // Basic validation first
            if (!$this->paymentProof) {
                throw new \Exception("Payment proof not uploaded");
            }
            
            if (empty($this->selectedSeats)) {
                throw new \Exception("No seats selected");
            }
            
            // Upload payment proof first
            $path = $this->upload();
            
            if (!$path) {
                throw new \Exception("Failed to upload payment proof");
            }
            
            // Debug log - Capture semua data yang dikirim
            Log::info('Complete Payment button clicked', [
                'user_id' => Auth::id(),
                'payment_method' => $this->paymentMethod,
                'selected_bank' => $this->selectedBank,
                'has_payment_proof' => true,
                'proof_path' => $path,
                'selected_seats' => $this->selectedSeats,
                'total_amount' => $this->totalAmount,
            ]);
            
            // Tambahkan alert untuk melihat di browser juga
            $this->dispatch('alert', [
                'message' => 'Processing payment request...',
                'type' => 'info'
            ]);
            
            // Periksa apakah sesi checkout sudah kedaluwarsa
            if ($this->countdownExpired) {
                $this->dispatch('alert', [
                    'message' => 'Your booking session has expired. Please try again.',
                    'type' => 'error'
                ]);
                return;
            }
            
            // Validasi input
            $this->validate([
                'paymentMethod' => 'required|in:bank_transfer',
                'paymentProof' => 'required|image|max:2048', // Max 2MB
            ], [
                'paymentProof.required' => 'Please upload payment proof to continue',
                'paymentProof.image' => 'The payment proof must be an image (JPG, PNG, etc)',
                'paymentProof.max' => 'Payment proof image must not exceed 2MB',
            ]);
            
            // Sebelum memulai transaksi DB, kita set jeda untuk simulasi proses
            sleep(1);
            
            DB::beginTransaction();
            Log::debug('Starting transaction');
            
            // Create transaction
            $transaction = Transaksi::create([
                'user_id' => Auth::id(),
                'transaction_date' => Carbon::now(),
                'total_payment' => $this->totalAmount,
                'payment_method' => 'Bank Transfer - ' . strtoupper($this->selectedBank),
                'payment_status' => 'Pending',
                'payment_proof' => $path,
                'booking_code' => $this->bookingCode,
            ]);
            Log::debug('Transaction created', ['transaction_id' => $transaction->id]);
            
            // Create tickets
            foreach ($this->selectedSeats as $seatNumber) {
                // Find or create the seat
                $kursi = Kursi::firstOrCreate(
                    [
                        'studio_id' => $this->studio->id,
                        'chair_number' => $seatNumber,
                    ],
                    [
                        'line' => substr($seatNumber, 0, 1),
                        'coloumn' => (int)substr($seatNumber, 1),
                    ]
                );
                
                // Create ticket
                $ticket = Tiket::create([
                    'jadwal_tayang_id' => $this->jadwalId,
                    'kursi_id' => $kursi->id,
                    'price' => $this->ticketPrice,
                    'ticket_status' => 'Reserved',
                    'transaksi_id' => $transaction->id,
                ]);
                
                // Create transaction detail
                DetailTransaksi::create([
                    'transaction_id' => $transaction->id,
                    'ticket_id' => $ticket->id,
                    'ticket_quantity' => 1,
                    'unit_price' => $this->ticketPrice,
                ]);
                
                Log::debug('Ticket created', [
                    'transaction_id' => $transaction->id,
                    'seat' => $seatNumber,
                    'ticket_id' => $ticket->id ?? null
                ]);
            }
            
            DB::commit();
            Log::debug('Transaction committed successfully');
            
            // Clear checkout session
            session()->forget(['selected_seats', 'jadwal_id', 'checkout_expires_at']);
            
            // Set transaction complete flag
            $this->transactionComplete = true;
            
            // Show success notification
            $this->dispatch('alert', [
                'message' => 'Your booking has been submitted successfully! Your booking code is ' . $this->bookingCode,
                'type' => 'success'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Show error notification with detailed message
            $this->dispatch('alert', [
                'message' => 'Transaction failed: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }
    
    public function render()
    {
        // Get remaining time
        $expiresAt = session('checkout_expires_at');
        if ($expiresAt) {
            $now = Carbon::now();
            $expiresAt = Carbon::parse($expiresAt);
            
            if ($now->gte($expiresAt)) {
                $this->countdownExpired = true;
                $this->countdownMinutes = 0;
                $this->countdownSeconds = 0;
            } else {
                $diffInSeconds = $expiresAt->diffInSeconds($now);
                $this->countdownMinutes = floor($diffInSeconds / 60);
                $this->countdownSeconds = $diffInSeconds % 60;
            }
        }
        
        return view('livewire.user.checkout');
    }
    
    // Tambahkan metode untuk toggle debug mode
    public function toggleDebugMode()
    {
        $this->debugMode = !$this->debugMode;
        if ($this->debugMode) {
            Log::channel('daily')->debug('Debug mode enabled', [
                'user_id' => Auth::id(),
                'current_state' => [
                    'payment_method' => $this->paymentMethod,
                    'selected_bank' => $this->selectedBank,
                    'has_payment_proof' => $this->paymentProof ? true : false,
                    'selected_seats' => $this->selectedSeats,
                    'jadwal_id' => $this->jadwalId,
                    'total_amount' => $this->totalAmount,
                ]
            ]);
        }
    }
    
    public function testAction()
    {
        $this->dispatch('alert', [
            'type' => 'info',
            'message' => 'Test action successful at ' . now()->format('H:i:s')
        ]);
        
        Log::info('Test action triggered');
    }
    
    public function upload()
    {
        try {
            // Check if file exists
            if (!$this->paymentProof) {
                throw new \Exception("No file selected");
            }

            // Log file information for debugging
            Log::info('Attempting to upload file', [
                'original_name' => $this->paymentProof->getClientOriginalName(),
                'mime_type' => $this->paymentProof->getMimeType(),
                'size' => $this->paymentProof->getSize(),
            ]);

            // Generate a simpler filename to avoid any path issues
            $filename = 'payment_' . time() . '_' . rand(1000, 9999) . '.' . $this->paymentProof->getClientOriginalExtension();
            
            // Try alternative storage method - first let's just store in the root public directory
            $path = $this->paymentProof->storePublicly('payment_proofs', 'public');
            
            // Debug log for the path
            Log::info('File storage attempted', [
                'intended_path' => $path,
                'exists_check' => Storage::disk('public')->exists($path) ? 'File exists' : 'File missing'
            ]);
            
            // Verify storage was successful
            if (!Storage::disk('public')->exists($path)) {
                // Try alternative method
                $directPath = public_path('storage/payment_proofs');
                
                // Make sure directory exists
                if (!file_exists($directPath)) {
                    mkdir($directPath, 0777, true);
                }
                
                // Move file manually
                $this->paymentProof->move($directPath, $filename);
                $path = 'payment_proofs/' . $filename;
                
                Log::info('Manual file move attempted', [
                    'new_path' => $path,
                    'exists' => file_exists(public_path('storage/' . $path)) ? 'Yes' : 'No'
                ]);
                
                // Final check
                if (!file_exists(public_path('storage/' . $path))) {
                    throw new \Exception("Failed to save file. Check server permissions.");
                }
            }
            
            $this->dispatch('alert', [
                'message' => 'Payment proof uploaded successfully',
                'type' => 'success'
            ]);
            
            return $path;
            
        } catch (\Exception $e) {
            Log::error('Payment proof upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->dispatch('alert', [
                'message' => 'Upload failed: ' . $e->getMessage(),
                'type' => 'error'
            ]);
            
            return null;
        }
    }
}
