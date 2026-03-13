"""Shared pytest configuration for vascular_mcp tests."""
import os
import sys

# Make the parent directory (vascular_mcp/) importable as 'server'
_vascular_mcp_dir = os.path.dirname(os.path.dirname(__file__))
sys.path.insert(0, _vascular_mcp_dir)

# Load .env so LARAVEL_BASE_URL / LARAVEL_API_KEY are available to all test modules
from dotenv import load_dotenv
load_dotenv(os.path.join(_vascular_mcp_dir, ".env"))
