<?php

namespace Ayangzy\RealSeed\Semantics;

use Ayangzy\RealSeed\Generation\SeededRandom;
use Illuminate\Support\Str;

/**
 * Readable baseline text, which AI planning refines. Common entities (tasks, products, posts, ...) get
 * realistic values; anything else gets plain, neutral business language built from
 * the table's own name. Never lorem ipsum, never novel excerpts.
 */
final class Vocabulary
{
    /** Table entity (singular) => canonical vocabulary key. */
    private const ALIASES = [
        'task' => 'task', 'todo' => 'task', 'ticket' => 'task', 'issue' => 'task', 'card' => 'task', 'subtask' => 'task',
        'project' => 'project', 'initiative' => 'project',
        'product' => 'product', 'item' => 'product', 'sku' => 'product', 'variant' => 'product',
        'post' => 'post', 'article' => 'post', 'blog' => 'post', 'story' => 'post', 'news' => 'post', 'page' => 'post',
        'comment' => 'comment', 'reply' => 'comment', 'note' => 'comment', 'message' => 'comment', 'feedback' => 'comment', 'review' => 'review', 'testimonial' => 'review',
        'event' => 'event', 'meeting' => 'event', 'appointment' => 'event', 'session' => 'event', 'booking' => 'event',
        'course' => 'course', 'lesson' => 'course', 'module' => 'course', 'class' => 'course', 'training' => 'course',
        'category' => 'category', 'tag' => 'category', 'label' => 'category', 'genre' => 'category', 'topic' => 'category',
        'team' => 'team', 'department' => 'team', 'group' => 'team', 'unit' => 'team', 'division' => 'team',
        'property' => 'property', 'listing' => 'property', 'apartment' => 'property', 'house' => 'property',
        'campaign' => 'campaign', 'promotion' => 'campaign',
        'document' => 'document', 'file' => 'document', 'attachment' => 'document', 'report' => 'document',
        'service' => 'service', 'plan' => 'plan', 'subscription' => 'plan', 'package' => 'plan',
        'job' => 'job', 'vacancy' => 'job', 'opening' => 'job',
        'branch' => 'branch', 'location' => 'branch', 'store' => 'branch', 'warehouse' => 'branch', 'outlet' => 'branch',
        'announcement' => 'announcement', 'notification' => 'announcement', 'alert' => 'announcement',
        'faq' => 'faq', 'question' => 'faq',
        'invoice' => 'billing', 'order' => 'billing', 'payment' => 'billing', 'transaction' => 'billing', 'expense' => 'billing', 'charge' => 'billing',
    ];

    /** key => [kind => values]; kinds: title, name, text */
    private const ENTITIES = [
        'task' => [
            'title' => [
                'Fix login redirect on mobile', 'Update onboarding email copy', 'Review pull request for checkout flow',
                'Prepare Q3 budget summary', 'Schedule vendor follow-up call', 'Migrate reports to new dashboard',
                'Add export to CSV on orders page', 'Investigate slow search results', 'Draft release notes for v2.4',
                'Clean up unused feature flags', 'Set up staging database backups', 'Confirm delivery dates with supplier',
                'Reconcile March bank statement', 'Write tests for payment webhook', 'Design empty state for inbox',
                'Update privacy policy page', 'Resolve duplicate customer records', 'Plan team offsite agenda',
                'Review contract renewal terms', 'Improve password reset flow', 'Translate help articles',
                'Audit user permissions', 'Optimise product images', 'Prepare demo for client meeting',
                'Fix timezone bug in reminders', 'Collect feedback from beta users', 'Update tax rates for new region',
                'Archive completed projects', 'Configure SSL renewal alerts', 'Onboard new support agent',
                'Reduce dashboard load time', 'Add two-factor authentication', 'Refresh sales pipeline report',
                'Follow up on overdue invoices', 'Set quarterly OKRs', 'Review expense claims',
            ],
            'text' => [
                'Customers are seeing this on the latest version.', 'Please confirm with finance before closing.',
                'Blocked until the API keys are rotated.', 'Steps to reproduce are in the linked thread.',
                'Needs design sign-off before development starts.', 'Agreed in Monday\'s planning meeting.',
                'Low priority, but it keeps coming up in support tickets.', 'Target is to ship this before month end.',
                'Pair with the backend team on the data migration.', 'Screenshots attached for reference.',
            ],
        ],
        'project' => [
            'name' => [
                'Website Redesign', 'Mobile App Launch', 'Q3 Marketing Campaign', 'Customer Portal', 'Payments Migration',
                'Data Warehouse Setup', 'Brand Refresh', 'Onboarding Revamp', 'Partner Integration', 'Inventory System Upgrade',
                'Support Knowledge Base', 'Annual Report 2026', 'Office Relocation', 'Sales Training Programme',
                'Security Compliance Review', 'Loyalty Programme', 'Analytics Dashboard', 'Checkout Optimisation',
                'Recruitment Drive', 'Cloud Cost Reduction', 'Product Catalogue Cleanup', 'Customer Survey 2026',
            ],
            'text' => [
                'Cross-team effort led by product, with support from engineering and marketing.',
                'Phase one covers research and planning; delivery follows in phase two.',
                'Budget approved for the current quarter. Weekly status updates on Fridays.',
                'Goal is to cut manual work for the operations team by half.',
            ],
        ],
        'product' => [
            'name' => [
                'Wireless Bluetooth Headphones', 'Stainless Steel Water Bottle 750ml', 'Cotton Crew Neck T-Shirt',
                'Ergonomic Office Chair', 'Ceramic Coffee Mug', 'Leather Card Holder', 'Portable Power Bank 20000mAh',
                'Organic Shea Butter 250g', 'Non-Stick Frying Pan 28cm', 'Running Shoes', 'Laptop Backpack',
                'Scented Soy Candle', 'Yoga Mat', 'Smart LED Bulb', 'Bamboo Cutting Board', 'Men\'s Slim Fit Jeans',
                'Ankara Print Tote Bag', 'Hair Dryer 2000W', 'Kids\' Picture Book Set', 'Mechanical Keyboard',
                'Insulated Lunch Box', 'Sunglasses with UV Protection', 'Electric Kettle 1.7L', 'Desk Lamp',
                'Jollof Spice Mix 200g', 'Wall Clock', 'Phone Case', 'Throw Pillow Cover', 'Travel Adapter', 'Notebook A5',
            ],
            'text' => [
                'Durable, lightweight and easy to clean.', 'Our best seller for three seasons running.',
                'Comes with a 12-month warranty.', 'Available in several colours and sizes.',
                'Made from responsibly sourced materials.', 'Ships within two working days.',
            ],
        ],
        'post' => [
            'title' => [
                '10 Tips for Managing Remote Teams', 'What We Learned Launching Our App', 'A Beginner\'s Guide to Budgeting',
                'How to Write a Great Product Description', 'Our 2026 Roadmap', 'Customer Story: Growing Sales by 40%',
                'Five Mistakes to Avoid When Hiring', 'Behind the Scenes of Our Latest Release', 'Why We Switched to Weekly Planning',
                'The Complete Guide to Invoicing', 'Improving Customer Support Response Times', 'Announcing Our New Partnership',
                'How We Reduced Costs Without Cutting Quality', 'Getting Started with Our API', 'Lessons from Our First Year',
            ],
            'text' => [
                'In this post we share what worked, what didn\'t, and what we would do differently.',
                'Here is a quick overview of the changes and how they affect you.',
                'We spoke to a few of our customers about how they use the product day to day.',
                'If you have questions, reply to this post or reach out to our support team.',
            ],
        ],
        'comment' => [
            'text' => [
                'Looks good to me.', 'Thanks, this is really helpful!', 'Can we get an update on this?',
                'I\'ve attached the latest version.', 'Agreed, let\'s go ahead.', 'Please review when you have a moment.',
                'Done. Let me know if anything else is needed.', 'I think we should wait until next week.',
                'Great work on this, team.', 'Could you clarify the second point?', 'Following up on this.',
                'I\'ll take care of it tomorrow morning.', 'This is fixed in the latest release.', 'Noted, thanks for flagging.',
                'Can we discuss this in the next meeting?', 'I\'ve updated the figures in the sheet.',
                'Customer confirmed this is working now.', 'Moving this to the next sprint.',
            ],
        ],
        'review' => [
            'title' => ['Excellent quality', 'Great value for money', 'Fast delivery', 'Does the job', 'Not what I expected', 'Highly recommend'],
            'text' => [
                'Arrived quickly and exactly as described.', 'Good quality for the price. Would buy again.',
                'Customer service was very helpful.', 'Works well, but the instructions could be clearer.',
                'Better than I expected. Recommended.', 'Took a while to arrive, but worth the wait.',
                'Solid product, no complaints so far.', 'Not quite the colour shown in the photos.',
            ],
        ],
        'event' => [
            'title' => [
                'Weekly Team Standup', 'Quarterly Business Review', 'Client Onboarding Call', 'Product Demo', 'Board Meeting',
                'Sprint Planning', 'Customer Feedback Session', 'All-Hands Meeting', 'Interview: Frontend Developer',
                'Budget Review', 'Training Workshop', 'Vendor Negotiation', 'Launch Party', 'One-on-One Check-in',
                'Annual General Meeting', 'Consultation', 'Follow-up Appointment', 'Strategy Workshop',
            ],
            'text' => [
                'Agenda will be shared a day before.', 'Please bring the latest figures.', 'Dial-in details are in the invite.',
                'Short session, 30 minutes max.', 'Lunch will be provided.',
            ],
        ],
        'course' => [
            'title' => [
                'Introduction to Accounting', 'Customer Service Essentials', 'Project Management Fundamentals',
                'Advanced Excel for Finance', 'Digital Marketing Basics', 'Leadership and Team Building',
                'Data Analysis with SQL', 'Public Speaking', 'Workplace Health and Safety', 'Sales Techniques',
                'Business Writing', 'Time Management', 'Introduction to Python', 'Financial Planning', 'UX Design Principles',
            ],
            'text' => [
                'A practical course with exercises at the end of each module.', 'Suitable for beginners; no prior experience needed.',
                'Includes a certificate on completion.', 'Self-paced, with optional live Q&A sessions.',
            ],
        ],
        'category' => [
            'name' => [
                'Electronics', 'Fashion', 'Home & Kitchen', 'Health & Beauty', 'Sports', 'Books', 'Office Supplies', 'Groceries',
                'Toys & Games', 'Automotive', 'Garden', 'Pet Supplies', 'Jewellery', 'Stationery', 'Furniture', 'Baby Products',
                'Urgent', 'Bug', 'Feature Request', 'Billing', 'Onboarding', 'Marketing', 'Finance', 'Operations',
            ],
        ],
        'team' => [
            'name' => [
                'Engineering', 'Marketing', 'Sales', 'Customer Support', 'Finance', 'Human Resources', 'Operations',
                'Product', 'Design', 'Legal', 'Procurement', 'Logistics', 'Customer Success', 'Research', 'IT Support',
            ],
        ],
        'property' => [
            'title' => [
                '3-Bedroom Flat with Parking', 'Modern 2-Bedroom Apartment', 'Detached Family Home with Garden',
                'Studio Apartment near Town Centre', 'Serviced Office Space', 'Semi-Detached Duplex', 'Penthouse with City View',
                'Newly Renovated Bungalow', '4-Bedroom Terrace House', 'Shop Space on Main Road', 'Furnished 1-Bedroom Apartment',
            ],
            'text' => [
                'Spacious rooms, fitted kitchen and 24-hour security.', 'Close to schools, shops and public transport.',
                'Recently renovated with new floors and plumbing.', 'Quiet neighbourhood with ample parking.',
            ],
        ],
        'campaign' => [
            'name' => [
                'Summer Sale', 'Black Friday Deals', 'New Year Promotion', 'Back to School', 'Refer a Friend', 'Spring Launch',
                'Customer Win-Back', 'Holiday Gift Guide', 'Flash Sale Weekend', 'Loyalty Rewards Push', 'Product Launch Teaser',
            ],
        ],
        'document' => [
            'title' => [
                'Q2 Financial Report', 'Employee Handbook', 'Service Agreement', 'Project Proposal', 'Meeting Minutes',
                'Brand Guidelines', 'Onboarding Checklist', 'Audit Report', 'Product Specification', 'Invoice Template',
                'Annual Budget', 'Risk Assessment', 'Marketing Plan', 'Signed Contract',
            ],
        ],
        'service' => [
            'name' => [
                'Website Maintenance', 'Consultation', 'Deep Cleaning', 'Tax Filing', 'Logo Design', 'IT Support', 'Delivery',
                'Installation', 'Annual Servicing', 'Bookkeeping', 'Photography Session', 'Home Tutoring', 'Legal Review',
            ],
        ],
        'plan' => [
            'name' => ['Free', 'Starter', 'Basic', 'Pro', 'Business', 'Premium', 'Enterprise', 'Team', 'Growth', 'Unlimited'],
        ],
        'job' => [
            'title' => [
                'Senior Backend Developer', 'Customer Support Specialist', 'Accountant', 'Sales Executive', 'Product Designer',
                'Operations Manager', 'Marketing Coordinator', 'Data Analyst', 'HR Generalist', 'Logistics Officer',
            ],
        ],
        'branch' => [
            'name' => [
                'Head Office', 'Main Branch', 'Downtown Branch', 'Airport Outlet', 'North Warehouse', 'City Centre Store',
                'Mall Outlet', 'East Branch', 'West Branch', 'Distribution Centre',
            ],
        ],
        'announcement' => [
            'title' => [
                'Scheduled maintenance this weekend', 'New features are live', 'Office closed on public holiday',
                'Updated terms of service', 'Welcome our new team members', 'Price changes from next month',
                'Your monthly summary is ready', 'Password reset required',
            ],
        ],
        'faq' => [
            'title' => [
                'How do I reset my password?', 'Can I change my plan later?', 'How long does delivery take?',
                'What payment methods do you accept?', 'How do I cancel my subscription?', 'Is my data secure?',
                'Can I get a refund?', 'How do I contact support?',
            ],
        ],
        'billing' => [
            'text' => [
                'Monthly subscription - Pro plan', 'Consulting services for May', 'Annual licence renewal', 'Delivery fee',
                'Office supplies', 'Website hosting', 'Setup and onboarding', 'Late payment fee', 'Product order', 'Refund',
                'Travel expenses', 'Marketing services', 'Software licences', 'Maintenance contract',
            ],
        ],
    ];

    /** Country => bank names (organisations, not people, so real names are fine). */
    private const BANKS = [
        'NG' => ['Access Bank', 'GTBank', 'Zenith Bank', 'First Bank of Nigeria', 'UBA', 'Fidelity Bank', 'Union Bank',
            'Sterling Bank', 'Wema Bank', 'Stanbic IBTC', 'Ecobank Nigeria', 'Polaris Bank', 'Moniepoint', 'Opay', 'Kuda Bank'],
        'GB' => ['Barclays', 'HSBC UK', 'Lloyds Bank', 'NatWest', 'Santander UK', 'Monzo', 'Starling Bank', 'Nationwide'],
        'default' => ['Chase', 'Bank of America', 'Wells Fargo', 'Citibank', 'Capital One', 'US Bank', 'PNC Bank', 'TD Bank'],
    ];

    private const VERBS = [
        'Review', 'Update', 'Prepare', 'Finalise', 'Schedule', 'Follow up on', 'Draft', 'Approve', 'Plan', 'Organise',
        'Check', 'Confirm', 'Complete', 'Submit', 'Share', 'Improve', 'Set up', 'Clean up', 'Reconcile', 'Discuss',
    ];

    private const OBJECTS = [
        'monthly report', 'budget', 'proposal', 'contract', 'onboarding', 'client feedback', 'invoice', 'schedule',
        'presentation', 'inventory', 'training plan', 'supplier quote', 'launch checklist', 'policy document',
        'customer list', 'pricing', 'dashboard', 'handover notes', 'expense claims', 'campaign brief',
    ];

    private const QUALIFIERS = [
        'Standard', 'Premium', 'Annual', 'Quarterly', 'Monthly', 'Internal', 'Customer', 'Regional', 'Priority', 'Core',
        'Primary', 'Express', 'Corporate', 'Community', 'Partner', 'Seasonal', 'Global', 'Local', 'Advanced', 'Basic',
    ];

    private const AREAS = [
        'North', 'South', 'East', 'West', 'Central', 'Downtown', 'Main', 'Head Office', 'Online', 'Retail',
        'Wholesale', 'Enterprise', 'Small Business', 'Operations', 'Finance', 'Sales', 'Support', 'Marketing', 'Product', 'Admin',
    ];

    private const SENTENCES = [
        'Updated after the call with the client.', 'Waiting for approval from the finance team.',
        'All documents have been received.', 'Scheduled for review next week.', 'Customer requested a follow-up.',
        'Completed ahead of schedule.', 'Needs a second review before sign-off.', 'Figures were checked against last month.',
        'Delivery was confirmed by the supplier.', 'Shared with the team for comments.', 'Pending payment confirmation.',
        'Notes from the planning meeting are attached.', 'Priority raised after customer feedback.', 'Everything is on track.',
        'Minor changes requested by the manager.', 'Moved to the next phase.', 'Handled by the support team.',
        'Awaiting a response from the vendor.', 'Checked and verified.', 'To be revisited at the end of the quarter.',
    ];

    /**
     * Realistic values for a known entity, or null.
     *
     * @return list<string>|null
     */
    public static function samples(string $table, string $kind): ?array
    {
        $key = self::key($table);

        if ($key === null) {
            return null;
        }

        $entity = self::ENTITIES[$key];

        return $entity[$kind] ?? match ($kind) {
            'title' => $entity['name'] ?? null,
            'name' => $entity['title'] ?? null,
            default => null,
        };
    }

    /**
     * A short, action-style title, e.g. "Review quarterly budget".
     */
    public static function title(SeededRandom $random): string
    {
        return $random->pick(self::VERBS).' '.$random->pick(self::OBJECTS);
    }

    /**
     * A name built from the table itself, e.g. "Quarterly Workflow" for a workflows table.
     */
    public static function name(string $table, SeededRandom $random): string
    {
        $noun = Str::headline(Str::singular(Str::afterLast(strtolower($table), '_')));

        return $random->chance(0.5)
            ? $random->pick(self::QUALIFIERS).' '.$noun
            : $random->pick(self::AREAS).' '.$random->pick(self::QUALIFIERS).' '.$noun;
    }

    /**
     * @return list<string>
     */
    public static function banks(string $countryCode): array
    {
        return self::BANKS[$countryCode] ?? self::BANKS['default'];
    }

    public static function sentence(SeededRandom $random): string
    {
        return $random->pick(self::SENTENCES);
    }

    public static function paragraph(SeededRandom $random): string
    {
        $sentences = [];

        foreach (range(1, $random->int(2, 4)) as $_) {
            $sentences[] = $random->pick(self::SENTENCES);
        }

        return implode(' ', array_unique($sentences));
    }

    /**
     * A plain readable word for otherwise unrecognised text columns.
     */
    public static function word(SeededRandom $random): string
    {
        return $random->pick(self::QUALIFIERS);
    }

    private static function key(string $table): ?string
    {
        $entity = Str::singular(Str::afterLast(strtolower($table), '_'));

        return self::ALIASES[$entity] ?? null;
    }
}
