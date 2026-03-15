# push_adapter.py — runs inside the OpenWebUI container
import sqlite3, json

content = open('/tmp/vascular_mcp_adapter.py').read()

GUIDELINE_ENUM = [
    'aortic_arch', 'descending_thoracic_aorta', 'abdominal_aortic_aneurysm',
    'mesenteric_renal', 'asymptomatic_pad', 'clti', 'acute_limb_ischaemia',
    'carotid_vertebral', 'venous_thrombosis', 'chronic_venous_disease',
    'antithrombotic_therapy', 'vascular_trauma', 'vascular_graft_infections',
    'vascular_access',
]

specs = json.dumps([{
    'name': 'consult_vascular_guidelines',
    'description': (
        'Consult ESVS Vascular Guidelines. Select 1-3 guidelines based on the clinical '
        'question. Call this tool for any vascular surgery clinical or guideline question, '
        'including any follow-up in an ongoing vascular case. NEVER answer from a prior tool '
        'result in history alone; each new follow-up may require fresh retrieval or backend '
        'change detection.'
    ),
    'parameters': {
        'type': 'object',
        'properties': {
            'question': {
                'type': 'string',
                'description': 'The clinical question',
            },
            'guideline_1': {
                'type': 'string',
                'enum': GUIDELINE_ENUM,
                'description': 'Primary guideline (required)',
            },
            'guideline_2': {
                'type': 'string',
                'enum': GUIDELINE_ENUM,
                'description': 'Secondary guideline (optional)',
            },
            'guideline_3': {
                'type': 'string',
                'enum': GUIDELINE_ENUM,
                'description': 'Tertiary guideline (optional)',
            },
        },
        'required': ['question', 'guideline_1'],
    },
}])

conn = sqlite3.connect('/app/backend/data/webui.db')
conn.execute(
    'INSERT OR REPLACE INTO tool '
    '(id, user_id, name, content, specs, meta, updated_at, created_at) '
    "VALUES (?,?,?,?,?,?,strftime('%s','now'),strftime('%s','now'))",
    (
        'vascular_mcp_adapter',
        '01e147c8-3cb6-4907-8261-f81402aac1f6',
        'Vascular MCP Adapter',
        content,
        specs,
        '{}',
    )
)
# Set valves to match production mcp tool
valves = json.dumps({
    'VASCULAR_API_BASE_URL': 'https://lavarel.eastus2.cloudapp.azure.com',
    'VASCULAR_API_KEY': 'gukUXd551qIobQVHVQLedUMmA4E8Cx4s',
    'EMIT_STATUS_AS_MESSAGES': True,
    'EMIT_STATUS_EVENTS': False,
})
conn.execute(
    "UPDATE tool SET valves=? WHERE id='vascular_mcp_adapter'",
    (valves,)
)
conn.commit()

# Verify both tools are present
for r in conn.execute('SELECT id, name FROM tool ORDER BY id'):
    print(r)
conn.close()
print('Done')
