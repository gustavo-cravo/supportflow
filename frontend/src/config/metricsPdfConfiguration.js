import { metricFilterName, metricLabelWithoutPlate } from './ticketMetrics'
import titlePolicy from '../../../resources/reports/metrics-pdf-title.json'

export const metricsPdfConfigurationEndpoint = 'metrics/export/pdf/configuration'

export function createMetricsPdfDraft(catalog) {
    const { defaults, sections, vehicle_grouping } = catalog
    return {
        sections: Object.fromEntries(
            Object.entries(sections).map(([key, section]) => [
                key,
                {
                    indicators: Object.fromEntries(
                        Object.keys(section.indicators).map((id) => [
                            id,
                            { enabled: defaults.enabled, format: defaults.format },
                        ]),
                    ),
                    ...(key === vehicle_grouping.section && {
                        grouping: {
                            primary: defaults.grouping.primary,
                            additional: [...defaults.grouping.additional],
                        },
                    }),
                },
            ]),
        ),
        title: { ...defaults.title },
        subtitle: defaults.subtitle ?? '',
    }
}

export function setPdfSection(catalog, draft, key, enabled, all = false) {
    const { indicators, grouping } = draft.sections[key]
    const selected = all ? Object.keys(indicators) : catalog.sections[key].activation_indicators
    for (const [id, option] of Object.entries(indicators)) {
        option.enabled = enabled && selected.includes(id)
    }
    if (!grouping) return
    if (!enabled) {
        grouping.primary = null
        grouping.additional = []
    } else if (!grouping.primary) {
        grouping.primary = {
            by: catalog.vehicle_grouping.activation_primary,
            format: catalog.defaults.format,
        }
    }
}

export function resetPdfFormats(draft, format) {
    for (const { indicators, grouping } of Object.values(draft.sections)) {
        for (const option of Object.values(indicators)) option.format = format
        if (grouping?.primary) grouping.primary.format = format
        for (const group of grouping?.additional ?? []) group.format = format
    }
}

export function effectiveMetricsPdfConfiguration(catalog, draft) {
    const sections = {}
    for (const [key, { indicators, grouping }] of Object.entries(draft.sections)) {
        const selected = Object.fromEntries(
            Object.entries(indicators)
                .filter(([, option]) => option.enabled)
                .map(([id, option]) => [
                    id,
                    {
                        enabled: true,
                        ...(catalog.sections[key].indicators[id].formats.length && {
                            format: option.format,
                        }),
                    },
                ]),
        )
        if (grouping ? grouping.primary : Object.keys(selected).length) {
            sections[key] = {
                enabled: true,
                indicators: selected,
                ...(grouping && {
                    grouping: {
                        primary: { ...grouping.primary },
                        additional: grouping.additional.map((group) => ({ ...group })),
                    },
                }),
            }
        }
    }
    const title =
        draft.title.mode === catalog.defaults.title.mode
            ? { ...catalog.defaults.title }
            : { mode: draft.title.mode, text: draft.title.text.trim() }
    return { sections, title, subtitle: draft.subtitle.trim() || null }
}

export function summarizeMetricsPdf(catalog, { sections }) {
    const active = Object.values(sections)
    const grouping = sections[catalog.vehicle_grouping.section]?.grouping
    const formats = [
        ...active.flatMap((section) =>
            Object.values(section.indicators).map(({ format }) => format),
        ),
        grouping?.primary.format,
        ...(grouping?.additional.map(({ format }) => format) ?? []),
    ]
    return {
        sections: active.length,
        indicators: active.reduce(
            (total, section) => total + Object.keys(section.indicators).length,
            0,
        ),
        customized: formats.some((format) => format && format !== catalog.defaults.format),
        additional:
            grouping?.additional.map(({ by }) => catalog.vehicle_grouping.dimensions[by].label) ??
            [],
    }
}

const indication = new RegExp(titlePolicy.topicPattern, 'u')
const vehicleTopic = new RegExp(titlePolicy.vehicleTopicPattern, 'u')
const labelPattern = new RegExp(titlePolicy.labelPattern, 'u')

function normalizeTopic(name) {
    return [...name.toLowerCase().trim().replace(/\p{M}/gu, '').replace(/\s+/g, ' ')]
        .map((character) => titlePolicy.replacements[character] ?? character)
        .join('')
}

function safeTitleLabel(name) {
    // Title qualifiers come from catalog names. Leave out names with digits or other
    // characters that could carry plate-like values or markup.
    return name && labelPattern.test(name) ? name.trim() : ''
}

export function automaticMetricsPdfTitle(catalog, { sections }, filters, filterCatalog, brandName) {
    const qualifierLabel = (name) =>
        metricLabelWithoutPlate(safeTitleLabel(name), filters.vehicle_plate)
    const system = qualifierLabel(metricFilterName(filterCatalog.systems, filters.system_id))
    const brand = filters.vehicle_brand_id ? qualifierLabel(brandName) : ''
    const category = metricFilterName(filterCatalog.categories, filters.problem_category_id)
    const subcategory = metricFilterName(
        filterCatalog.subcategories,
        filters.problem_subcategory_id,
    )
    const topic = [subcategory, category].map(normalizeTopic).find((name) => indication.test(name))
    const grouping = sections[catalog.vehicle_grouping.section]?.grouping
    const selected = Object.keys(sections)
    const vehicleOnly =
        selected.length > 0 &&
        selected.every((key) => key === 'vehicles' || key === catalog.vehicle_grouping.section)
    const vehicleScope =
        vehicleOnly ||
        filters.vehicle_presence === 'with' ||
        filters.vehicle_brand_id ||
        filters.vehicle_model ||
        filters.vehicle_plate ||
        filters.vehicle_line

    const conditions = {
        vehicleIndication: topic && ((vehicleScope && !brand) || vehicleTopic.test(topic)),
        indication: !!topic,
        modelOnly: grouping?.primary.by === 'model' && selected.length === 1,
        vehicleOnly,
        namedScope: system || brand,
        summaryOnly: selected.length === 1 && !!sections.summary,
    }
    const title =
        Object.entries(titlePolicy.rules).find(([rule]) => conditions[rule])?.[1] ??
        titlePolicy.fallback

    const qualifier = [brand, system]
        .filter(Boolean)
        .map((name) => title + titlePolicy.separator + name)
        .find((candidate) => Array.from(candidate).length <= catalog.title.max_length)
    return qualifier || title
}
