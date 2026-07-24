$(document).ready(function() {
    // Initialize DataTables
    if ($('#dataTable').length) {
        $('#dataTable').DataTable({
            responsive: true,
            pageLength: 25,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "Showing 0 to 0 of 0 entries",
                infoFiltered: "(filtered from _MAX_ total entries)"
            }
        });
    }
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
    
    // Confirm delete
    $('.delete-btn').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this item?')) {
            e.preventDefault();
        }
    });
    
    // Format currency inputs
    $('.currency-input').on('blur', function() {
        let value = parseFloat($(this).val());
        if (!isNaN(value)) {
            $(this).val(value.toFixed(2));
        }
    });
    
    // Validate quantity inputs
    $('.qty-input').on('change', function() {
        let value = parseInt($(this).val());
        if (value < 0) {
            $(this).val(0);
        }
    });
});

// Custom functions
function showLoader() {
    $('#loader').fadeIn();
}

function hideLoader() {
    $('#loader').fadeOut();
}

function printPage() {
    window.print();
}

function exportToExcel(tableId, filename) {
    let table = document.getElementById(tableId);
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let a = document.createElement('a');
    a.href = url;
    a.download = filename + '.xls';
    a.click();
}

// AJAX functions
function updateStatus(poId, status) {
    if (confirm('Are you sure you want to change the status to ' + status + '?')) {
        $.ajax({
            url: 'api/pos.php?action=update-status',
            method: 'POST',
            data: {
                po_id: poId,
                status: status,
                remarks: prompt('Remarks (optional):')
            },
            success: function(response) {
                location.reload();
            },
            error: function() {
                alert('Error updating status');
            }
        });
    }
}

// Search with debounce
let searchTimeout;
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        $('#searchForm').submit();
    }, 500);
}