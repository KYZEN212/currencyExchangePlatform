<?php
// PHP logic to define the FAQ data (simulating fetching from a database or API)
$faqs = [
    [
        'id' => 1,
        'q' => 'How do I open an account?',
        'a' => '<p>Click the <strong>"Sign Up"</strong> button on our homepage. The process takes less than five minutes and requires a valid email address, mobile phone number and the <strong>NRC</strong>(your valid ID).<p>'
    ],
    [
        'id' => 2,
        'q' => 'What exchange rate will I receive?',
        'a' => '<p>The exchange rate you receive is the <strong>Live Interbank Rate</strong> plus a small, transparent margin. The final rate is locked in when you confirm your transaction and is displayed clearly on the order screen before you commit.<p>'
    ],
    [
        'id' => 3,
        'q' => 'Do you charge any fees?',
        'a' => '<p>Yes.We charge <strong>a small, flat transaction fee</strong> which varies based on the currency pair and transaction size. This fee is always displayed upfront and is included in the final cost shown to you. There are <strong>no hidden fees</strong>.</p>'
    ],
    [
        'id' => 4,
        'q' => 'What is the difference between the "Live Rate" and the "Transacted Rate"?',
        'a' => '<p>The <strong>Live Rate</strong> is the real-time interbank rate, which constantly fluctuates. The <strong>Transacted Rate</strong> is the specific rate we offer you, which includes our minimal margin and is guaranteed for a short period once you initiate the transfer.</p>'
    ],
    [
        'id' => 5,
        'q' => 'How long does the verification process take?',
        'a' => '<p>Verification usually takes <strong>less than 24 hours</strong> once all required documents have been submitted and received during business hours.</p>'
    ],
    [
        'id' => 6,
        'q' => 'How do I make a currency exchange order?',
        'a' => '<p>1. <strong>Log in</strong> to your account.</p>
                <p>2. <strong>Select</strong> the currency you are <strong>Sending</strong> and the currency you want to <strong>Receive</strong>.<p>
                <p>3. <strong>Enter</strong> the amount and <strong>click</strong> "Buy All" or "Buy Partial".</p>
                <p>4. <strong>Review</strong> the guaranteed rate, fee, and total amount.</p>
                <p>5. <strong>Confirm</strong> the order and transfer your funds to our segregated client account.</p>
                <p>6. <strong>Mail</strong> to the corresponding users email <strong>with Trade Details</strong> after successful trade.</p>'
            ],
    [
        'id' => 7,
        'q' => 'How long does a withdraw take to arrive?',
        'a' => '<p><strong>Transfer times</strong> vary based on the currency and destination, but typical timelines are:
                <p>1. <strong>Same-day</strong> or <strong>1 business day</strong> for major currency (MMK).</p>
                <p>2. <strong>1-3 business days</strong> for other currencies. You will receive a confirmation email when the funds are sent out.</p></p>'
    ],
    [
        'id' => 8,
        'q' => 'What kind of information does Accqura collect?',
        'a' => '<p>We may collect information about you in a variety of ways. The information we may collect on the Site includes:</p>
            <ul class="list-disc ml-5 mt-2 space-y-1">
                <li><strong>Personal Data:</strong> Personally identifiable information, such as your name and email address, voluntarily provided during registration.</li>
                <li><strong>Financial Data:</strong> We **do not** collect sensitive financial information like credit card numbers. We log necessary transaction details (e.g., currency pairs, amount, time) only to provide our service.</li>
                <li><strong>Derivative Data:</strong> Information our servers automatically collect (e.g., IP address, browser type) to monitor site usage and improve service.</li>
            </ul>'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accqura FAQ</title>
    <!-- Load Tailwind CSS for modern, responsive styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom styles for the collapsible transition */
        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-in-out, padding 0.3s ease-in-out;
        }
        .content-show {
            /* This height should be large enough to accommodate the content */
            max-height: 500px; 
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">

<div class="container mx-auto p-4 sm:p-8 md:p-12 max-w-3xl">

    <header class="text-center mb-10">
        <h1 class="text-4xl font-extrabold text-indigo-700 mb-2">Accqura FAQ & Policy</h1>
        <p class="text-md text-gray-600">
            <strong>Welcome!</strong>to our <strong>Accqura FAQs</strong> page! Here, we answer the most common questions <em>If you have any other questions, feel free to ask in the comments or join our community!</em>
    </header>

    <!-- FAQ Section generated by PHP -->
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden divide-y divide-gray-100">
        
        <?php foreach ($faqs as $faq): 
            // Generate the unique IDs for JavaScript targeting
            $contentId = 'content-' . $faq['id'];
            $symbolId = 'symbol-' . $faq['id'];
            $headerId = 'header-' . $faq['id'];
            $onClickHandler = "toggleContent('$contentId', '$symbolId', '$headerId')";
        ?>
        
        <!-- Collapsible Header -->
        <button 
            id="<?= $headerId ?>"
            class="collapsible-header w-full flex items-center justify-between text-left p-4 sm:p-6 text-xl font-semibold text-gray-800 hover:bg-indigo-50 transition duration-150 ease-in-out focus:outline-none"
            onclick="<?= $onClickHandler ?>"
            aria-expanded="false" 
            aria-controls="<?= $contentId ?>"
        >
            <span class="flex-grow pr-4"><?= htmlspecialchars($faq['q']) ?></span>
            <!-- Plus/Minus Symbol -->
            <span id="<?= $symbolId ?>" class="text-2xl text-indigo-500 font-bold leading-none transition-transform duration-300 transform">+</span>
        </button>

        <!-- Collapsible Content -->
        <div 
            id="<?= $contentId ?>" 
            class="collapsible-content px-4 sm:px-6 text-gray-600 bg-gray-50 border-t border-indigo-100"
            role="region"
            aria-labelledby="<?= $headerId ?>"
        >
            <!-- Note: The HTML in $faq['a'] is directly echoed here -->
            <?= $faq['a'] ?>
        </div>

        <?php endforeach; ?>

    </div>


</div>

<!-- JavaScript for Collapsible Functionality -->
<script>
    /**
     * Toggles the visibility of the content box and updates the symbol and header classes.
     * @param {string} contentId - The ID of the content div to collapse/expand.
     * @param {string} symbolId - The ID of the span containing the + / - symbol.
     * @param {string} headerId - The ID of the header button.
     */
    function toggleContent(contentId, symbolId, headerId) {
        const content = document.getElementById(contentId);
        const symbol = document.getElementById(symbolId);
        const header = document.getElementById(headerId);

        // 1. Toggle the visibility of the content area
        const isExpanded = content.classList.toggle('content-show');

        // 2. Update the + or - symbol based on the new state
        if (isExpanded) {
            symbol.textContent = '–'; // Minus symbol
            // Removed 'rotate-180' class as it was causing the symbol to appear sideways for some characters
            symbol.classList.add('text-indigo-700');
            symbol.classList.remove('rotate-90'); // Ensure it's not rotated
            header.setAttribute('aria-expanded', 'true');
        } else {
            symbol.textContent = '+'; // Plus symbol
            symbol.classList.remove('text-indigo-700');
            header.setAttribute('aria-expanded', 'false');
        }
    }

    // Optional: Auto-collapse any open sections if clicking outside or handle initial state
    document.addEventListener('DOMContentLoaded', () => {
        // Find all content blocks and ensure they are initially hidden
        document.querySelectorAll('.collapsible-content').forEach(content => {
            content.classList.remove('content-show');
        });
    });

</script>

</body>
</html>