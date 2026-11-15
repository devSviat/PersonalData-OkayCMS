{if $sviat_pd_badge}
    {$sviat_pd_badge_label = $btr->getTranslation("sviat__personal_data__badge_`$sviat_pd_badge->state`")}
    {if $manager->permissions && in_array('sviat__personal_data__log', $manager->permissions)}
        <a class="sviat-pd-badge sviat-pd-badge--{$sviat_pd_badge->state|escape}" href="{$sviat_pd_badge->url|escape}" title="{$btr->sviat__personal_data__badge_open|escape}">
            <span>{$sviat_pd_badge_label|escape}</span>
            <time datetime="{$sviat_pd_badge->date|escape}">{$sviat_pd_badge->date|date|escape}</time>
        </a>
    {else}
        <span class="sviat-pd-badge sviat-pd-badge--{$sviat_pd_badge->state|escape}">
            <span>{$sviat_pd_badge_label|escape}</span>
            <time datetime="{$sviat_pd_badge->date|escape}">{$sviat_pd_badge->date|date|escape}</time>
        </span>
    {/if}
{/if}
