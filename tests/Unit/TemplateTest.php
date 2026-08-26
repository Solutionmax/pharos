<?php

namespace Tests\Unit;

use App\Models\IncidentTemplate;
use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase
{
    protected function template(string $body): IncidentTemplate
    {
        return new IncidentTemplate(['title_template' => 'x', 'body_template' => $body]);
    }

    public function test_it_replaces_placeholders(): void
    {
        $this->assertSame(
            'Outage on web-06, started 16:50.',
            $this->template('Outage on {{server}}, started {{started_at}}.')
                ->render('body_template', ['server' => 'web-06', 'started_at' => '16:50']),
        );
    }

    public function test_it_tolerates_whitespace_inside_the_braces(): void
    {
        $this->assertSame(
            'Outage on web-06.',
            $this->template('Outage on {{ server }}.')->render('body_template', ['server' => 'web-06']),
        );
    }

    public function test_an_unknown_placeholder_is_left_alone(): void
    {
        // Blanking it would publish "Outage on ." to customers.
        $this->assertSame(
            'Outage on {{server}}.',
            $this->template('Outage on {{server}}.')->render('body_template', []),
        );
    }

    public function test_it_leaves_text_without_placeholders_untouched(): void
    {
        $this->assertSame(
            'We are investigating.',
            $this->template('We are investigating.')->render('body_template', ['server' => 'web-06']),
        );
    }
}
