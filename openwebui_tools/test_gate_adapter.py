import unittest

from gate_adapter import Tools


class AskOnlyTools(Tools):
    def __init__(self, response):
        super().__init__()
        self.response = response
        self.valves.EMIT_STATUS = False

    async def _post_consult(self, client, message, state, request_id):
        return self.response


class GateAdapterTest(unittest.IsolatedAsyncioTestCase):
    async def test_ask_renders_questions_only_returns_state_and_emits_no_citations(self):
        state = {"patient_model": {"symptom_status": "unknown"}, "version": 3}
        tool = AskOnlyTools(
            {
                "decision": "ask",
                "questions": [{"question": "Is the patient symptomatic?"}],
                "answer_markdown": "THIS ANSWER MUST NOT BE RENDERED [1].",
                "citations": [{"id": "1", "document": "Hidden citation"}],
                "assets": [{"url": "https://example.invalid/hidden.png"}],
                "state": state,
            }
        )
        events = []

        result = await tool.clinical_gate(
            "What should I do?",
            __event_emitter__=events.append,
        )

        self.assertEqual(
            {
                "clarification": "## Questions\n\n- Is the patient symptomatic?",
                "state": state,
            },
            result,
        )
        self.assertNotIn("THIS ANSWER", result["clarification"])
        self.assertNotIn("[1]", result["clarification"])
        self.assertEqual([], events)


if __name__ == "__main__":
    unittest.main()
