<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$page_title = "Sell Currency";
include '../php/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Sell Currency</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Please select the currency you want to sell and enter the amount.
                    </div>
                    
                    <form id="sellForm" class="mt-4">
                        <div class="mb-3">
                            <label for="currency" class="form-label">Select Currency</label>
                            <select class="form-select" id="currency" required>
                                <option value="" selected disabled>Choose currency...</option>
                                <option value="USD">US Dollar (USD)</option>
                                <option value="MMK">Myanmar Kyat (MMK)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="amount" min="0.01" step="0.01" placeholder="Enter amount" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="paymentMethod" class="form-label">Payment Method</label>
                            <select class="form-select" id="paymentMethod" required>
                                <option value="" selected disabled>Select payment method...</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mobile">Mobile Payment</option>
                                <option value="crypto">Cryptocurrency</option>
                            </select>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-2"></i>Place Sell Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card mt-4 shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Recent Sell Orders</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Currency</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <i class="fas fa-exchange-alt fa-2x text-muted mb-2"></i>
                                        <p class="mb-0">No recent sell orders</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('sellForm').addEventListener('submit', function(e) {
    e.preventDefault();
    // Add your form submission logic here
    alert('Sell order placed successfully!');
    this.reset();
});
</script>

<?php include '../php/footer.php'; ?>
